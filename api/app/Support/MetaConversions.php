<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\MarketingCredential;
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
     * os únicos slots livres. Quem dá nome de gente é a conversão personalizada criada
     * pelo `capi:conversoes` — é ela que aparece como objetivo na hora de montar a
     * campanha, com o rótulo "CRM · Reunião marcada".
     */
    public const LEAD = 'LeadSubmitted';
    public const REUNIAO_MARCADA = 'InitiateCheckout';
    public const REUNIAO_REALIZADA = 'ViewContent';
    public const VENDA = 'Purchase';

    /** Rótulo humano de cada evento, para log e para a tela. */
    public const ROTULOS = [
        self::LEAD => 'Lead chegou no CRM',
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
    public static function enviar(Conversation $conversation, string $evento, array $custom = []): bool
    {
        try {
            return self::despachar($conversation, $evento, $custom);
        } catch (\Throwable $e) {
            Log::warning('capi: falha ao enviar evento', [
                'conversa' => $conversation->id,
                'evento' => $evento,
                'erro' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private static function despachar(Conversation $conversation, string $evento, array $custom): bool
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
        $waba = \App\Models\WaAccount::where('provider', 'cloud')->whereNotNull('waba_id')->value('waba_id');
        if (! $waba) {
            return false;
        }

        // event_id estável: se o mesmo evento for reenviado (retry, reprocessamento de
        // webhook), a Meta deduplica em vez de contar a conversão duas vezes.
        $eventId = "{$evento}:{$conversation->id}";

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
            self::marcar($conversation, $evento);
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
    public static function enviarUmaVez(Conversation $conversation, string $evento, array $custom = []): bool
    {
        if (self::jaEnviado($conversation, $evento)) {
            return false;
        }

        return self::enviar($conversation, $evento, $custom);
    }
}
