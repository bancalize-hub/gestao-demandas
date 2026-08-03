<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\WaAccount;
use App\Services\CampaignMessageService;
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

        // Número migrado para a API oficial depois da campanha criada: parar é melhor que
        // queimar a lista marcando todo mundo como falha (lá fora da janela de 24h só sai
        // template aprovado). A tela mostra o motivo na campanha pausada.
        if ($acct->isCloud()) {
            $campaign->update(['status' => 'paused']);
            $this->warn("campanha {$campaign->id}: pausada — o número está na API oficial, que não faz disparo fora da janela de 24h");

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

        // Janela de horário (Brasília): só dispara dentro do expediente configurado.
        $hm = now()->format('H:i');
        if ($hm < $campaign->window_start || $hm > $campaign->window_end) {
            return;
        }

        // Reset diário do contador + ramp de aquecimento (1 passo por dia ativo).
        $today = now()->toDateString();
        if ((string) $acct->sent_date !== $today) {
            $acct->sent_date = $today;
            $acct->sent_today = 0;
            $acct->warmup_day = (int) $acct->warmup_day + 1;
            $acct->save();
        }

        // Teto diário do NÚMERO (com aquecimento) e da CAMPANHA.
        if ($acct->remainingToday() <= 0) {
            return;
        }
        $sentTodayCampaign = $campaign->contacts()
            ->whereIn('status', ['sent', 'replied'])
            ->whereDate('sent_at', $today)
            ->count();
        if ($campaign->daily_cap > 0 && $sentTodayCampaign >= $campaign->daily_cap) {
            return;
        }

        // Intervalo aleatório desde o último envio DESTE número (vale entre campanhas que compartilham o número).
        $gap = random_int($campaign->min_gap_s, max($campaign->min_gap_s, $campaign->max_gap_s));
        if ($acct->last_sent_at && $acct->last_sent_at->diffInSeconds(now()) < $gap) {
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
        if (! Wa::for($acct)->isOnWhatsApp($phone)) {
            $contact->update(['status' => 'skipped', 'error' => 'não está no WhatsApp']);
            $campaign->refreshCounts();

            return;
        }

        $text = $ai->generate($campaign, $contact);
        if ($text === null) {
            // Não marca falha definitiva: tenta de novo no próximo tick (IA pode estar indisponível).
            $this->warn("campanha {$campaign->id}: IA não gerou mensagem para contato {$contact->id}");

            return;
        }

        $waId = Wa::for($acct)->sendText($phone, $text);
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
