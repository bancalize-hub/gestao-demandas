<?php

namespace App\Console\Commands;

use App\Models\LeadActivity;
use App\Models\Task;
use App\Services\AiReplyService;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Follow-up vencido: para cada follow-up (tarefa type='followup') com horário no
 * passado, ainda pendente e sem rascunho, a IA gera uma mensagem de retomada.
 * NÃO envia nada — o vendedor revisa e dispara pelo chat (botão "Abrir no chat").
 */
class FollowUpTick extends Command
{
    protected $signature = 'followup:tick';

    protected $description = 'Gera rascunho de mensagem (IA) para os follow-ups vencidos';

    public function handle(AiReplyService $ai): int
    {
        $due = Task::where('type', 'followup')
            ->whereNotNull('conversation_id')
            ->where('column', '!=', 'done')
            ->whereNull('ai_draft')
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now())
            ->with('conversation')
            ->limit(20)
            ->get();

        $tenancy = app(Tenancy::class);

        foreach ($due as $task) {
            $conv = $task->conversation;
            if (! $conv) {
                continue;
            }

            // Contexto da empresa dona do follow-up (rascunho/atividade carimbados).
            $tenancy->set((int) $task->company_id);

            try {
            $draft = $ai->generate(
                $conv,
                "Escreva uma mensagem curta e cordial de follow-up para retomar o contato com este lead, "
                ."considerando o contexto da conversa e o objetivo da etapa atual do funil. "
                ."Tarefa do follow-up: \"{$task->title}\". Não invente informações nem prometa nada que não foi combinado.",
            );

            if ($draft === null || trim($draft) === '') {
                $this->warn("followup: falha ao gerar rascunho p/ task {$task->id}");

                continue;
            }

            $task->forceFill(['ai_draft' => mb_substr($draft, 0, 4000), 'ai_draft_at' => now()])->saveQuietly();

            LeadActivity::log($conv->id, 'followup', 'Follow-up venceu — rascunho de mensagem pronto', null);

            // Avisa os painéis abertos (ficha/acompanhamentos) em tempo real — só a empresa dona.
            try {
                \App\Events\CrmUpdated::dispatch('followup', (int) $task->company_id);
            } catch (\Throwable $e) {
            }

            $this->info("followup: rascunho gerado p/ conversa {$conv->id}");
            } finally {
                $tenancy->forget();
            }
        }

        return self::SUCCESS;
    }
}
