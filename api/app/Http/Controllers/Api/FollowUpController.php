<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\LeadActivity;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Follow-ups de acompanhamento do lead. São tarefas (type='followup') ligadas a uma
 * conversa. Criação manual; no vencimento o comando followup:tick gera um rascunho de
 * mensagem (IA) e o vendedor decide enviar pelo chat.
 */
class FollowUpController extends Controller
{
    /** Follow-ups da conversa, pendentes primeiro, por data. */
    public function index(Conversation $conversation)
    {
        return Task::where('conversation_id', $conversation->id)
            ->where('type', 'followup')
            // `column` é palavra reservada no MySQL: sem crase, a lista inteira morria em
            // erro de sintaxe (1064) e a ficha do lead ficava sem os follow-ups.
            ->orderByRaw("`column` = 'done'")          // pendentes antes
            ->orderByRaw('starts_at IS NULL, starts_at')
            ->orderBy('id')
            ->get();
    }

    public function store(Request $request, Conversation $conversation)
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'starts_at' => 'required|date',
            'priority' => 'nullable|in:baixa,media,alta',
        ]);

        $task = Task::create([
            'conversation_id' => $conversation->id,
            'type' => 'followup',
            'title' => $data['title'],
            'client' => $conversation->name,
            'priority' => $data['priority'] ?? 'media',
            'column' => 'todo',
            'starts_at' => $data['starts_at'],
            'due' => Carbon::parse($data['starts_at'])->format('d/m H:i'),
            'position' => (Task::where('column', 'todo')->min('position') ?? 0) - 1,
        ]);

        LeadActivity::log(
            $conversation->id,
            'followup',
            'Follow-up agendado para '.Carbon::parse($data['starts_at'])->format('d/m \à\s H:i'),
            $data['title'],
            $request->user()?->id,
        );

        return response()->json($task->fresh(), 201);
    }

    /** Marca o follow-up como concluído. */
    public function complete(Request $request, Task $task)
    {
        abort_unless($task->type === 'followup', 404);
        $task->update(['column' => 'done']);

        if ($task->conversation_id) {
            LeadActivity::log($task->conversation_id, 'followup', 'Follow-up concluído', $task->title, $request->user()?->id);
        }

        return response()->json($task->fresh());
    }
}
