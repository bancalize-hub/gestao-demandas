<?php

namespace App\Http\Controllers\Api;

use App\Events\CrmUpdated;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\TaskLabel;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * O cartão por dentro: mover de lista, arquivar, etiquetas, responsáveis, checklist,
 * comentários e anexos.
 *
 * Tudo aqui é rota ANINHADA na tarefa (`/tasks/{task}/...`) de propósito: o filho
 * (item, comentário, anexo) não tem company_id, quem carrega o isolamento por empresa
 * é a tarefa, que o CompanyScope já filtra. Cada método confere que o filho é mesmo
 * daquela tarefa antes de mexer — sem isso, um id de outra empresa entraria pela URL.
 */
class TaskCardController extends Controller
{
    /** Detalhe completo do cartão (o que o modal precisa). */
    public function show(Task $task)
    {
        return response()->json($this->detalhe($task));
    }

    /**
     * Move o cartão para uma lista e uma POSIÇÃO dentro dela (o arrastar do Trello).
     * A ordem é reescrita inteira na lista de destino (e na de origem): são dezenas de
     * cartões, não milhares, e reindexar evita o empate de posição que faz o cartão
     * pular de lugar sozinho no próximo carregamento.
     */
    public function move(Request $request, Task $task)
    {
        $data = $request->validate([
            'task_list_id' => ['required', 'integer', Rule::in(TaskList::whereNull('archived_at')->pluck('id')->all())],
            'index' => 'nullable|integer|min:0',
        ]);

        $origem = $task->task_list_id;
        $destino = (int) $data['task_list_id'];

        $ids = Task::query()->noQuadro()
            ->where('task_list_id', $destino)
            ->where('id', '!=', $task->id)
            ->orderBy('position')->orderBy('id')
            ->pluck('id')->all();

        $index = min((int) ($data['index'] ?? count($ids)), count($ids));
        array_splice($ids, $index, 0, [$task->id]);

        $task->forceFill(['task_list_id' => $destino])->save();
        $this->reindexar($ids);

        if ($origem && $origem !== $destino) {
            $this->reindexar(
                Task::query()->noQuadro()->where('task_list_id', $origem)
                    ->orderBy('position')->orderBy('id')->pluck('id')->all()
            );
        }

        $this->avisar();

        return response()->json(BoardController::cartao($this->recarregar($task)));
    }

    public function archive(Task $task)
    {
        $task->update(['archived_at' => now(), 'archived_with_list' => false]);
        $this->avisar();

        return response()->json(['message' => 'ok']);
    }

    public function restore(Task $task)
    {
        // Lista arquivada junto? O cartão volta para a primeira lista aberta — senão ele
        // "voltaria" para um lugar que não existe mais no quadro.
        $lista = $task->task_list_id ? TaskList::whereNull('archived_at')->find($task->task_list_id) : null;
        $destino = $lista?->id ?? TaskList::whereNull('archived_at')->orderBy('position')->value('id');

        $task->update([
            'archived_at' => null,
            'archived_with_list' => false,
            'task_list_id' => $destino,
            'position' => (Task::query()->manual()->where('task_list_id', $destino)->min('position') ?? 0) - 1,
        ]);
        $this->avisar();

        return response()->json(BoardController::cartao($this->recarregar($task)));
    }

    // ----- Etiquetas e responsáveis -----

    public function syncLabels(Request $request, Task $task)
    {
        $data = $request->validate([
            'label_ids' => 'present|array',
            'label_ids.*' => ['integer', Rule::in(TaskLabel::pluck('id')->all())],
        ]);

        $task->labels()->sync($data['label_ids']);
        $this->avisar();

        return response()->json(BoardController::cartao($this->recarregar($task)));
    }

    public function syncMembers(Request $request, Task $task)
    {
        $doTime = User::when(
            $request->user()?->company_id,
            fn ($q) => $q->where('company_id', $request->user()->company_id)
        )->pluck('id')->all();

        $data = $request->validate([
            'user_ids' => 'present|array',
            'user_ids.*' => ['integer', Rule::in($doTime)],
        ]);

        $task->members()->sync($data['user_ids']);
        $this->avisar();

        return response()->json(BoardController::cartao($this->recarregar($task)));
    }

    // ----- Checklist -----

    public function storeChecklistItem(Request $request, Task $task)
    {
        $data = $request->validate(['text' => 'required|string|max:500']);

        $item = $task->checklist()->create([
            'text' => $data['text'],
            'position' => ($task->checklist()->max('position') ?? -1) + 1,
        ]);
        $this->avisar();

        return response()->json($item, 201);
    }

    public function updateChecklistItem(Request $request, Task $task, TaskChecklistItem $item)
    {
        $this->confereDono($item->task_id, $task);

        $data = $request->validate([
            'text' => 'sometimes|string|max:500',
            'done' => 'sometimes|boolean',
        ]);

        $item->update($data);
        $this->avisar();

        return response()->json($item->fresh());
    }

    public function destroyChecklistItem(Task $task, TaskChecklistItem $item)
    {
        $this->confereDono($item->task_id, $task);
        $item->delete();
        $this->avisar();

        return response()->json(['message' => 'ok']);
    }

    // ----- Comentários -----

    public function storeComment(Request $request, Task $task)
    {
        $data = $request->validate(['body' => 'required|string|max:5000']);

        $comment = $task->comments()->create([
            'user_id' => $request->user()?->id,
            'author_name' => $request->user()?->name,
            'body' => $data['body'],
        ]);
        $this->avisar();

        return response()->json($this->comentario($comment), 201);
    }

    public function destroyComment(Request $request, Task $task, TaskComment $comment)
    {
        $this->confereDono($comment->task_id, $task);
        // Comentário é de quem escreveu; só o autor ou um admin apaga.
        abort_unless(
            $comment->user_id === $request->user()?->id || $request->user()?->is_admin,
            403,
            'Só quem escreveu (ou um admin) pode apagar o comentário.'
        );

        $comment->delete();
        $this->avisar();

        return response()->json(['message' => 'ok']);
    }

    // ----- Anexos -----

    public function storeAttachment(Request $request, Task $task)
    {
        $data = $request->validate([
            'file' => 'required|file|max:20480',   // 20MB
        ]);

        $file = $data['file'];
        $path = $file->store('task-attachments', 'local');

        $anexo = $task->attachments()->create([
            'user_id' => $request->user()?->id,
            'name' => $file->getClientOriginalName() ?: basename($path),
            'path' => $path,
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => (int) $file->getSize(),
        ]);
        $this->avisar();

        return response()->json($this->anexo($anexo), 201);
    }

    public function showAttachment(Task $task, TaskAttachment $attachment)
    {
        $this->confereDono($attachment->task_id, $task);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return response(Storage::disk('local')->get($attachment->path), 200, [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($attachment->name).'"',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function destroyAttachment(Task $task, TaskAttachment $attachment)
    {
        $this->confereDono($attachment->task_id, $task);
        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();
        $this->avisar();

        return response()->json(['message' => 'ok']);
    }

    // ----- Apoio -----

    private function detalhe(Task $task): array
    {
        $task->load(['checklist', 'comments.user:id,name', 'attachments']);

        return BoardController::cartao($this->recarregar($task)) + [
            'checklist' => $task->checklist->map(fn ($i) => [
                'id' => $i->id, 'text' => $i->text, 'done' => (bool) $i->done, 'position' => $i->position,
            ])->all(),
            'comments' => $task->comments->map(fn ($c) => $this->comentario($c))->all(),
            'attachments' => $task->attachments->map(fn ($a) => $this->anexo($a))->all(),
        ];
    }

    private function comentario(TaskComment $c): array
    {
        return [
            'id' => $c->id,
            'body' => $c->body,
            'user_id' => $c->user_id,
            'author' => $c->user?->name ?: ($c->author_name ?: 'Alguém'),
            'created_at' => optional($c->created_at)->toIso8601String(),
        ];
    }

    private function anexo(TaskAttachment $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'mime' => $a->mime,
            'size' => (int) $a->size,
            'is_image' => str_starts_with((string) $a->mime, 'image/'),
            'url' => url("/api/tasks/{$a->task_id}/attachments/{$a->id}"),
            'created_at' => optional($a->created_at)->toIso8601String(),
        ];
    }

    /** Recarrega as contagens/relações que o cartão do quadro mostra. */
    private function recarregar(Task $task): Task
    {
        return Task::query()
            ->with(['labels:id', 'members:id,name,email'])
            ->withCount(['comments', 'attachments'])
            ->withCount(['checklist as checklist_total', 'checklist as checklist_done' => fn ($q) => $q->where('done', true)])
            ->findOrFail($task->id);
    }

    /** Reescreve a ordem da lista: posição = lugar do id no array. */
    private function reindexar(array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            Task::where('id', $id)->update(['position' => $i]);
        }
    }

    private function confereDono(?int $taskId, Task $task): void
    {
        abort_unless($taskId === $task->id, 404);
    }

    private function avisar(): void
    {
        broadcast(new CrmUpdated('board'))->toOthers();
    }
}
