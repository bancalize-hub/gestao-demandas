<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Company;
use App\Models\CampaignContact;
use App\Models\Conversation;
use App\Models\WaAccount;
use App\Services\CampaignMessageService;
use App\Support\Attendance;
use App\Support\Realtime;
use App\Support\Tenancy;
use App\Support\Wa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Motor de disparo/prospecção: para cada campanha ativa, envia a PRÓXIMA mensagem
 * respeitando o anti-ban conservador — intervalo aleatório entre envios por número,
 * teto diário com aquecimento gradual, e janela de horário. Um envio por tick/campanha.
 */
class CampaignTick extends Command
{
    protected $signature = 'campaigns:tick';

    protected $description = 'Dispara a próxima mensagem das campanhas de prospecção (anti-ban conservador)';

    public function handle(CampaignMessageService $ai): int
    {
        $tenancy = app(Tenancy::class);

        // Global (sem tenant): campanhas ativas de TODAS as empresas.
        $campaigns = Campaign::where('status', 'running')->get();

        foreach ($campaigns as $campaign) {
            try {
                // Processa no contexto da empresa dona: conversas/mensagens carimbadas
                // e o envio usa o número (instância) de prospecção dela.
                $tenancy->run((int) $campaign->company_id, fn () => $this->processCampaign($campaign, $ai));
            } catch (\Throwable $e) {
                Log::warning('campaigns:tick erro', ['campaign' => $campaign->id, 'e' => $e->getMessage()]);
            }
        }

        return self::SUCCESS;
    }

    private function processCampaign(Campaign $campaign, CampaignMessageService $ai): void
    {
        $acct = $campaign->account;
        if (! $acct || ! $acct->is_active) {
            return;
        }

        // Canal oficial dispara por TEMPLATE aprovado. Sem template configurado não há o
        // que enviar (texto livre a quem nunca escreveu é recusado pela Meta) — pausa com
        // o motivo em vez de marcar a lista inteira como falha, um contato por vez.
        if ($acct->isCloud() && ! $campaign->template_name) {
            $campaign->update(['status' => 'paused']);
            $this->warn("campanha {$campaign->id}: pausada — número oficial exige template aprovado");

            return;
        }

        // Número precisa estar conectado.
        $state = Wa::for($acct)->connectionState();
        if ($state !== 'open') {
            if ($acct->state !== $state) {
                $acct->update(['state' => $state]);
            }

            return;
        }

        // Agendamento: não começa antes da hora marcada (vale nos dois canais).
        if ($campaign->starts_at && now()->lt($campaign->starts_at)) {
            return;
        }

        // Reset diário do contador + ramp de aquecimento (1 passo por dia ativo).
        $today = now()->toDateString();
        // `?->toDateString()`, nunca (string): o cast 'date' vira Carbon e (string) dele
        // é 'Y-m-d H:i:s' — jamais igual a toDateString(). Com o guard sempre verdadeiro,
        // sent_today zerava e warmup_day subia A CADA MINUTO, e o teto do número com
        // aquecimento nunca segurou nada.
        if ($acct->sent_date?->toDateString() !== $today) {
            $acct->sent_date = $today;
            $acct->sent_today = 0;
            $acct->warmup_day = (int) $acct->warmup_day + 1;
            $acct->save();
        }

        // JANELA, TETO DA CAMPANHA e INTERVALO valem em TODO canal. No Baileys são
        // anti-ban; no oficial protegem outra coisa — a QUALIDADE da WABA, que a Meta
        // rebaixa quando template de marketing sai em rajada e fora de hora. Já saiu
        // disparo de domingo à noite por esta brecha ("no oficial a Meta cuida do
        // ritmo" — cuida do limite técnico, não da reputação do número).
        $hm = now()->format('H:i');
        if ($hm < $campaign->window_start || $hm > $campaign->window_end) {
            return;
        }

        // O DIA vem do horário de atendimento da EMPRESA (campanha só tem hora própria):
        // é o que fecha de vez a brecha do disparo de domingo — janela de hora sozinha
        // deixava passar qualquer dia da semana.
        if (! in_array(now()->isoWeekday(), Attendance::dias(Company::find($campaign->company_id)), true)) {
            return;
        }

        $sentTodayCampaign = $campaign->contacts()
            ->whereIn('status', ['sent', 'replied'])
            ->whereDate('sent_at', $today)
            ->count();
        if ($campaign->daily_cap > 0 && $sentTodayCampaign >= $campaign->daily_cap) {
            return;
        }

        // Intervalo aleatório desde o último envio DESTE número (vale entre campanhas
        // que compartilham o número).
        $gap = random_int($campaign->min_gap_s, max($campaign->min_gap_s, $campaign->max_gap_s));
        if ($acct->last_sent_at && $acct->last_sent_at->diffInSeconds(now()) < $gap) {
            return;
        }

        // Só o teto DO NÚMERO (warmup do Baileys) continua exclusivo da Evolution:
        // `remainingToday()` devolve 0 quando o número não tem teto configurado, e
        // aplicá-lo ao canal oficial travava a campanha para sempre, sem enviar nada.
        if ($campaign->usaAntiBan() && $acct->remainingToday() <= 0) {
            return;
        }

        // Próximo contato pendente (mais antigo primeiro).
        $contact = $campaign->contacts()->where('status', 'pending')->orderBy('id')->first();
        if (! $contact) {
            $campaign->update(['status' => 'done']);
            $this->info("campanha {$campaign->id} concluída");

            return;
        }

        $phone = preg_replace('/\D/', '', (string) $contact->phone);
        if ($phone === '') {
            $contact->update(['status' => 'failed', 'error' => 'telefone inválido']);

            return;
        }

        // Confirma que o número está no WhatsApp antes de gastar uma mensagem.
        // (Na Cloud API essa checagem não existe: lá a falha vira recibo de erro.)
        if (! Wa::for($acct)->isOnWhatsApp($phone)) {
            $contact->update(['status' => 'skipped', 'error' => 'não está no WhatsApp']);
            $campaign->refreshCounts();

            return;
        }

        if ($acct->isCloud()) {
            // Template: o texto é fixo e aprovado; o que muda por contato são as variáveis.
            $params = $this->paramsDoTemplate($campaign, $contact);
            $text = $this->renderTemplate((string) $campaign->template_body, $params, $campaign->template_name);

            $waId = Wa::for($acct)->sendTemplate(
                $phone,
                (string) $campaign->template_name,
                (string) ($campaign->template_language ?: 'pt_BR'),
                $params,
            );
        } else {
            $text = $ai->generate($campaign, $contact);
            if ($text === null) {
                // Não marca falha definitiva: tenta de novo no próximo tick (IA pode estar indisponível).
                $this->warn("campanha {$campaign->id}: IA não gerou mensagem para contato {$contact->id}");

                return;
            }

            $waId = Wa::for($acct)->sendText($phone, $text);
        }

        if ($waId === null) {
            $contact->update(['status' => 'failed', 'error' => 'falha no envio']);
            $campaign->refreshCounts();

            return;
        }

        // Cria/abre a conversa no CRM (carimbada com o número) para a resposta cair lá.
        $conv = $this->upsertConversation($acct, $phone, $contact->name, $text, $waId);

        $contact->update([
            'status' => 'sent',
            'message_text' => $text,
            'sent_at' => now(),
            'wa_id' => $waId ?: null,
            'conversation_id' => $conv->id,
        ]);

        // Atualiza o contador/anti-ban do número.
        $acct->update([
            'sent_today' => (int) $acct->sent_today + 1,
            'last_sent_at' => now(),
        ]);

        $campaign->refreshCounts();
        $this->info("campanha {$campaign->id}: enviado para {$phone} (contato {$contact->id})");
    }

    /**
     * Valores de cada {{n}} do template para ESTE contato. O admin escreve algo como
     * "{nome}" ou "sua operação" em cada variável; aqui as chaves viram o dado real.
     */
    private function paramsDoTemplate(Campaign $campaign, CampaignContact $contact): array
    {
        $primeiro = trim((string) strtok((string) $contact->name, ' '));
        $vars = (array) ($contact->vars ?? []);

        $base = [
            '{nome}' => $primeiro !== '' ? $primeiro : 'tudo bem',
            '{nome_completo}' => (string) ($contact->name ?: ''),
            '{telefone}' => (string) $contact->phone,
        ];
        foreach ($vars as $k => $v) {
            $base['{'.$k.'}'] = (string) $v;
        }

        return collect((array) ($campaign->template_params ?? []))
            // Variável de template NUNCA pode ir vazia — a Meta recusa a mensagem inteira.
            ->map(fn ($p) => trim(strtr((string) $p, $base)) ?: '-')
            ->values()->all();
    }

    /** Texto que fica no histórico: o corpo do template com as variáveis já trocadas. */
    private function renderTemplate(string $body, array $params, ?string $nome): string
    {
        foreach ($params as $i => $v) {
            $body = str_replace(['{{'.($i + 1).'}}', '{{ '.($i + 1).' }}'], $v, $body);
        }

        return trim($body) !== '' ? $body : '[template] '.$nome;
    }

    /** Cria (ou reusa) a conversa do contato no número de prospecção e registra a mensagem enviada. */
    private function upsertConversation(WaAccount $acct, string $phone, ?string $name, string $text, string $waId): Conversation
    {
        $slug = 'wa-'.$phone;
        $conv = Conversation::firstOrNew(['slug' => $slug]);
        if (! $conv->exists) {
            $display = trim((string) $name) ?: '+'.$phone;
            $conv->name = $display;
            $conv->initials = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $display) ?: 'C', 0, 2));
            $conv->color = '#6b7cff';
            $conv->position = (int) (Conversation::max('position') ?? 0) + 1;
        }
        $conv->origin = 'WhatsApp';
        $conv->phone = $conv->phone ?: '+'.$phone;
        $conv->wa_jid = $conv->wa_jid ?: $phone.'@s.whatsapp.net';
        $conv->wa_account_id = $conv->wa_account_id ?: $acct->id;
        $conv->auto_reply = false; // prospecção: resposta é atendida por humano
        $conv->preview = mb_substr($text, 0, 80);
        $conv->time = now()->format('H:i');
        $conv->last_message_at = now();
        $conv->save();

        $ts = time();
        $data = [
            'type' => 'text',
            'is_out' => true,
            'text' => mb_substr($text, 0, 4000),
            'time' => date('H:i', $ts),
            'ts' => $ts,
            'status' => 'sent',
            'position' => ((int) $conv->messages()->max('position')) + 1,
        ];
        $msg = $waId !== ''
            ? $conv->messages()->updateOrCreate(['wa_id' => $waId], $data)
            : $conv->messages()->create($data);
        Realtime::messageCreated($msg);

        return $conv;
    }
}
