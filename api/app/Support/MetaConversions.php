<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\MarketingCredential;
use App\Models\WaAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Conversions API da Meta: manda para o Facebook o que acontece com o lead DEPOIS do
 * clique — chegou no CRM, marcou reunião, compareceu, comprou.
 *
 * Por que server-side: o lead entra por Click-to-WhatsApp. Não há navegador, não há
 * pixel, não há página para disparar evento. O único lugar que sabe que a reunião
 * aconteceu é este sistema, e é ele que precisa contar para a Meta — só assim existe
 * campanha otimizada para "reunião realizada" em vez de para "clique".
 *
 * A CHAVE DE TUDO É O `ctwa_clid`. É o identificador do clique no anúncio, entregue
 * pela Meta no `referral` da primeira mensagem. Sem ele o evento chega, mas a Meta não
 * consegue ligar de volta ao anúncio, e ele não serve para otimizar nada. Por isso o
 * webhook guarda o referral inteiro e este serviço se recusa a mandar evento sem clid.
 *
 * @see WhatsAppCloudController::ingestMessage() — onde o clid é capturado
 */
class MetaConversions
{
    /** Marca de origem em todo evento nosso — ver o comentário no custom_data. */
    public const ORIGEM = 'crm';

    /**
     * Os quatro momentos do funil, mapeados no vocabulário que a Meta aceita.
     *
     * NÃO INVENTE NOME AQUI. Com `action_source: business_messaging` a Graph API recusa
     * qualquer evento fora de uma lista fechada — testei 28 candidatos contra a conta real
     * e passaram exatamente QUATRO: `Purchase`, `LeadSubmitted`, `InitiateCheckout` e
     * `ViewContent`. "Schedule", "Lead", "Contact", "MeetingCompleted" e companhia são
     * recusados com "nome de evento inválido" (subcode 2804066).
     *
     * Por isso as duas etapas do meio usam nomes que não descrevem o que aconteceu: são
     * os únicos slots livres.
     *
     * CADA MOMENTO DO FUNIL PRECISA DE UM NOME SÓ SEU — não dá para separar dois momentos
     * pelo `custom_data` do mesmo evento. Conversão personalizada NÃO enxerga evento com
     * `action_source: business_messaging`: ela é avaliada sobre evento de pixel/web.
     * Medido na conta em 08/08/2026 — "CRM · Lead no CRM" (regra `LeadSubmitted` +
     * `origem=crm`, as duas presentes no payload) recebeu ~87 eventos casáveis desde a
     * criação e tem `last_fired_time` VAZIO, enquanto "Demonstração" (`PageView` + URL,
     * evento de site) disparou normal. Nenhum `offsite_conversion.custom.*` aparece nas
     * ações da conta; os nossos chegam como `onsite_conversion.lead` e afins.
     *
     * Consequência: o que aparece no Gerenciador é o NOME DO EVENTO. Por isso
     * {@see self::LEAD} é gasto com o lead QUALIFICADO, que é o único alvo que vale
     * otimizar — ver o comentário lá.
     */

    /**
     * Lead QUALIFICADO — não "chegou um lead".
     *
     * Este nome já significou "lead entrou no CRM" e foi remanejado em 08/08/2026. O
     * motivo: sendo o nome do evento a única coisa que a Meta separa (ver acima), gastar
     * um dos quatro slots com "chegou lead" é desperdiçá-lo — a Meta JÁ conta isso
     * nativamente como conversa iniciada (`messaging_conversation_started_7d`), e a conta
     * já mediu que lead barato e lead bom andam em direções opostas. Agora a coluna
     * "Lead" do Gerenciador quer dizer lead que a triagem aprovou.
     */
    public const LEAD = 'LeadSubmitted';

    public const REUNIAO_MARCADA = 'InitiateCheckout';

    public const REUNIAO_REALIZADA = 'ViewContent';

    public const VENDA = 'Purchase';

    /**
     * A etapa continua indo no `custom_data`, mas NÃO é ela que separa nada para a Meta —
     * conversão personalizada não lê evento de business_messaging (ver acima). Ela fica
     * por dois motivos honestos: é o que se lê no Gerenciador de Eventos ao depurar um
     * evento específico, e é o que o `event_id` usa para deduplicar reenvio.
     *
     * NÃO volte a criar conversão personalizada filtrando por este campo esperando que
     * ela conte: já foi medido que não conta.
     */
    public const ETAPA_QUALIFICADO = 'qualificado';

    /** Rótulo humano de cada evento, para log e para a tela. */
    public const ROTULOS = [
        self::LEAD => 'Lead qualificado',
        self::REUNIAO_MARCADA => 'Reunião marcada',
        self::REUNIAO_REALIZADA => 'Reunião realizada',
        self::VENDA => 'Venda fechada',
    ];

    /**
     * Envia um evento do funil. Nunca estoura: uma falha de rede com o Facebook não
     * pode derrubar o agendamento de uma reunião nem o webhook de uma mensagem.
     *
     * @param  array<string, mixed>  $custom  custom_data (valor da venda, moeda…)
     * @return bool true se a Meta confirmou o recebimento
     */
    public static function enviar(Conversation $conversation, string $evento, array $custom = [], string $variante = ''): bool
    {
        try {
            return self::despachar($conversation, $evento, $custom, $variante);
        } catch (\Throwable $e) {
            Log::warning('capi: falha ao enviar evento', [
                'conversa' => $conversation->id,
                'evento' => $evento,
                'erro' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private static function despachar(Conversation $conversation, string $evento, array $custom, string $variante = ''): bool
    {
        $cred = MarketingCredential::atual();
        $dataset = trim((string) $cred->dataset_id);
        if ($dataset === '' || blank($cred->access_token)) {
            return false; // CAPI não configurada nesta empresa — silêncio, não é erro
        }

        $clid = self::clidDe($conversation);
        if (! $clid) {
            // Lead que não veio de anúncio (ou que chegou antes de guardarmos o clid).
            // Mandar sem clid gasta requisição e não otimiza nada.
            return false;
        }

        // Obrigatório junto com o clid: sem `whatsapp_business_account_id` (ou `page_id`)
        // a Graph API recusa o evento inteiro — subcode 2804116.
        $waba = WaAccount::where('provider', 'cloud')->whereNotNull('waba_id')->value('waba_id');
        if (! $waba) {
            return false;
        }

        // event_id estável: se o mesmo evento for reenviado (retry, reprocessamento de
        // webhook), a Meta deduplica em vez de contar a conversão duas vezes.
        $eventId = self::chave($evento, $variante).":{$conversation->id}";

        $payload = [
            'data' => [array_filter([
                'event_name' => $evento,
                'event_time' => now()->timestamp,
                'event_id' => $eventId,
                // Evento de conversa, não de site: é assim que a Meta liga ao clique
                // do anúncio de Click-to-WhatsApp.
                'action_source' => 'business_messaging',
                'messaging_channel' => 'whatsapp',
                'user_data' => ['ctwa_clid' => $clid, 'whatsapp_business_account_id' => $waba],
                // `origem` não é enfeite: a Graph API RECUSA conversão personalizada cuja
                // regra tenha só a condição de evento ("A conversion rule is required at
                // creation time"), exige uma segunda. É esta. De quebra, separa o evento
                // vindo do CRM do que o pixel do site dispara com o mesmo nome.
                'custom_data' => ['origem' => self::ORIGEM] + $custom,
            ], fn ($v) => $v !== null)],
        ];
        if (filled($cred->capi_test_code)) {
            $payload['test_event_code'] = $cred->capi_test_code;
        }

        $base = 'https://graph.facebook.com/'.trim($cred->graph_version ?: 'v23.0');
        $res = Http::timeout(20)->post("{$base}/{$dataset}/events", $payload + [
            'access_token' => $cred->access_token,
        ]);

        $json = $res->json();
        $ok = is_array($json) && (int) ($json['events_received'] ?? 0) > 0;

        Evolution::log('capi.evento', [
            'conversa' => $conversation->id,
            'evento' => $evento,
            'teste' => filled($cred->capi_test_code),
            'http' => $res->status(),
            'recebidos' => $json['events_received'] ?? null,
            'erro' => $json['error']['message'] ?? null,
        ], $ok ? 'info' : 'error');

        if ($ok) {
            self::marcar($conversation, self::chave($evento, $variante));
        }

        return $ok;
    }

    /** O identificador do clique no anúncio, guardado no referral da 1ª mensagem. */
    public static function clidDe(Conversation $conversation): ?string
    {
        $clid = data_get($conversation->custom_fields, 'anuncio.ctwa_clid');

        return is_string($clid) && $clid !== '' ? $clid : null;
    }

    /** Já mandamos este evento para esta conversa? (evita reenvio a cada tick) */
    public static function jaEnviado(Conversation $conversation, string $evento): bool
    {
        return (bool) data_get($conversation->custom_fields, "capi.{$evento}");
    }

    /** Carimba o envio no próprio lead — a trilha fica junto do que gerou o evento. */
    private static function marcar(Conversation $conversation, string $evento): void
    {
        $custom = (array) ($conversation->custom_fields ?? []);
        $custom['capi'] = ($custom['capi'] ?? []) + [$evento => now()->toIso8601String()];
        $conversation->custom_fields = $custom;
        $conversation->saveQuietly();
    }

    /**
     * Envia uma única vez por conversa. É o que os pontos do funil chamam: o tick de
     * presença roda de 5 em 5 minutos e não pode contar a mesma reunião várias vezes.
     */
    public static function enviarUmaVez(Conversation $conversation, string $evento, array $custom = [], string $variante = ''): bool
    {
        if (self::jaEnviado($conversation, self::chave($evento, $variante))) {
            return false;
        }

        return self::enviar($conversation, $evento, $custom, $variante);
    }

    /**
     * Lead qualificado. Mesmo evento do lead, etapa diferente — é o par do
     * {@see ETAPA_QUALIFICADO}. Uma vez por conversa: a triagem pode ser refeita à mão
     * e o otimizador não pode contar o mesmo lead bom duas vezes.
     */
    public static function enviarQualificado(Conversation $conversation): bool
    {
        return self::enviarUmaVez(
            $conversation,
            self::LEAD,
            ['etapa' => self::ETAPA_QUALIFICADO],
            self::ETAPA_QUALIFICADO,
        );
    }

    /** A chave que identifica evento+variante no carimbo e no event_id. */
    private static function chave(string $evento, string $variante): string
    {
        return $variante === '' ? $evento : "{$evento}:{$variante}";
    }
}
