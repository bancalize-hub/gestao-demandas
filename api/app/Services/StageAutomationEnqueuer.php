<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\StageAutomation;
use App\Models\StageAutomationRun;
use Illuminate\Support\Facades\Log;

/**
 * Enfileira os passos do playbook de uma etapa quando a conversa ENTRA nela.
 * Idempotente: o unique (conversation_id, step_id) + firstOrCreate garantem que
 * cada passo dispara no máximo uma vez por conversa, mesmo re-entrando na etapa.
 */
class StageAutomationEnqueuer
{
    public static function onEnterStage(Conversation $conversation): void
    {
        // Só faz sentido para conversas com WhatsApp (o canal de envio).
        if ($conversation->origin !== 'WhatsApp' || ! $conversation->phone) {
            return;
        }

        $automation = StageAutomation::with('steps')
            ->where('stage_key', $conversation->stage)
            ->where('enabled', true)
            ->first();
        if (! $automation || $automation->steps->isEmpty()) {
            return;
        }

        foreach ($automation->steps as $step) {
            try {
                StageAutomationRun::firstOrCreate(
                    ['conversation_id' => $conversation->id, 'step_id' => $step->id],
                    [
                        'automation_id' => $automation->id,
                        'status' => 'pending',
                        'run_at' => now()->addMinutes((int) $step->delay_minutes),
                    ],
                );
            } catch (\Throwable $e) {
                // Corrida (dois saves quase simultâneos) cai no unique — segue em frente.
                Log::debug('stage automation enqueue ignorado', ['e' => $e->getMessage()]);
            }
        }
    }
}
