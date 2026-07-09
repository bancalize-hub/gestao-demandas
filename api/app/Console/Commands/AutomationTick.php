<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\LeadActivity;
use App\Models\StageAutomationRun;
use App\Support\Evolution;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Dispara os passos de playbook vencidos: para cada run "pending" com run_at <= agora,
 * envia o texto/PDF pelo WhatsApp, espelha a mensagem no chat e registra na linha do
 * tempo do lead. Teto por tick para não enviar em rajada.
 */
class AutomationTick extends Command
{
    protected $signature = 'automations:tick';

    protected $description = 'Dispara as mensagens automáticas de etapa (playbook) que venceram';

    private const PER_TICK = 20;

    public function handle(): int
    {
        $runs = StageAutomationRun::with(['step', 'conversation'])
            ->where('status', 'pending')
            ->where('run_at', '<=', now())
            ->orderBy('run_at')
            ->limit(self::PER_TICK)
            ->get();

        $tenancy = app(Tenancy::class);

        foreach ($runs as $run) {
            try {
                // Processa no contexto da empresa dona do run (mensagens/atividades nascem
                // carimbadas e o envio usa o WhatsApp da empresa).
                $tenancy->run((int) $run->company_id, fn () => $this->processRun($run));
            } catch (\Throwable $e) {
                Log::warning('automations:tick erro', ['run' => $run->id, 'e' => $e->getMessage()]);
                $run->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);
            }
        }

        return self::SUCCESS;
    }

    private function processRun(StageAutomationRun $run): void
    {
        $step = $run->step;
        $conv = $run->conversation;
        if (! $step || ! $conv) {
            $run->update(['status' => 'skipped', 'error' => 'passo/conversa ausente']);

            return;
        }

        if ($conv->origin !== 'WhatsApp' || ! $conv->phone) {
            $run->update(['status' => 'skipped', 'error' => 'conversa sem WhatsApp']);

            return;
        }

        $phone = (string) $conv->phone;
        $inst = $conv->account?->instance;
        $text = $this->renderVars((string) ($step->text ?? ''), $conv);

        if ($step->type === 'media') {
            if (! $step->asset_path || ! Storage::disk('local')->exists($step->asset_path)) {
                $run->update(['status' => 'skipped', 'error' => 'sem PDF anexado']);

                return;
            }
            $base64 = base64_encode((string) Storage::disk('local')->get($step->asset_path));
            $mime = $step->asset_mime ?: 'application/pdf';
            $fileName = $step->asset_filename ?: 'arquivo.pdf';
            $waId = Evolution::sendMedia($phone, $base64, $mime, $fileName, $text, 'document', instance: $inst);
            if (! $waId) {
                $run->update(['status' => 'failed', 'error' => 'falha no envio da mídia']);

                return;
            }
            $this->mirrorMessage($conv, [
                'type' => 'file',
                'is_out' => true,
                'text' => $text !== '' ? $text : null,
                'file_name' => $fileName,
                'meta' => $mime,
            ], $waId, '📄 '.$fileName);
        } else {
            if ($text === '') {
                $run->update(['status' => 'skipped', 'error' => 'texto vazio']);

                return;
            }
            $waId = Evolution::sendText($phone, $text, instance: $inst);
            if (! $waId) {
                $run->update(['status' => 'failed', 'error' => 'falha no envio do texto']);

                return;
            }
            $this->mirrorMessage($conv, [
                'type' => 'text',
                'is_out' => true,
                'text' => mb_substr($text, 0, 4000),
            ], $waId, mb_substr($text, 0, 80));
        }

        LeadActivity::log($conv->id, 'whatsapp', 'Automação de etapa: mensagem enviada', $text !== '' ? mb_substr($text, 0, 500) : null);

        $run->update(['status' => 'sent', 'sent_at' => now(), 'wa_id' => $waId ?: null]);
        $this->info("automação: run {$run->id} enviada para {$phone}");
    }

    /** Grava a mensagem enviada na thread da conversa (mesma forma dos demais envios). */
    private function mirrorMessage(Conversation $conv, array $data, string $waId, string $preview): void
    {
        $ts = time();
        $data = array_merge($data, [
            'time' => date('H:i', $ts),
            'ts' => $ts,
            'status' => 'sent',
            'position' => ((int) $conv->messages()->max('position')) + 1,
        ]);
        $waId !== ''
            ? $conv->messages()->updateOrCreate(['wa_id' => $waId], $data)
            : $conv->messages()->create($data);

        $conv->update([
            'preview' => $preview,
            'time' => $data['time'],
            'last_message_at' => now(),
        ]);
    }

    /** Substitui as variáveis suportadas pelo conteúdo da ficha. */
    private function renderVars(string $text, Conversation $conv): string
    {
        $first = trim((string) strtok((string) $conv->name, ' ')) ?: (string) $conv->name;

        return trim(strtr($text, [
            '{nome}' => $first,
            '{nome_completo}' => (string) $conv->name,
            '{empresa}' => (string) $conv->company,
            '{responsavel}' => (string) $conv->responsible,
        ]));
    }
}
