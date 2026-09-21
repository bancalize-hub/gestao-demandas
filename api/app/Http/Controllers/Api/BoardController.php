<?php

namespace App\Http\Controllers\Api;

use App\Events\CrmUpdated;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Quadro de tarefas (/tarefas) — listas, etiquetas e os cartões que aparecem nelas.
 *
 * O cartão em si (checklist, comentário, anexo, responsável) é do TaskCardController;
 * aqui fica a moldura: as listas que a empresa monta, as etiquetas que ela usa e a
 * carga inicial da tela.
 */
class BoardController extends Controller
{
    /** As listas com que uma empresa começa, iguais às 4 que viviam no código do front. */
    private const LISTAS_PADRAO = [
        ['A fazer', '#8696a0'],
        ['Em andamento', '#53bdeb'],
        ['Em revisão', '#ffb443'],
        ['Concluído', '#25D366'],
    ];

    /** Paleta inicial de etiquetas — sem nome; quem usa batiza. */
    private const CORES_PADRAO = ['#25D366', '#ffb443', '#ff6b6b', '#a78bfa', '#53bdeb', '#8696a0'];

    public function index(Request $request)
    {
        $this->garantirPadroes();

        return response()->json([
            'lists' => TaskList::whereNull('archived_at')->orderBy('position')->orderBy('id')->get(),
            'labels' => TaskLabel::orderBy('position')->orderBy('id')->get(),
            'members' => $this->time($request),
            'cards' => $this->cartoes(Task::query()->noQuadro()),
        ]);
    }

    /** Arquivo: o que saiu do quadro sem ser apagado — cartões e listas. */
    public function archived()
    {
        return response()->json([
            'cards' => $this->cartoes(Task::query()->manual()->whereNotNull('archived_at')->orderByDesc('archived_at')),
            'lists' => TaskList::whereNotNull('archived_at')->orderByDesc('archived_at')->get(),
        ]);
    }

    // ----- Listas -----

    public function storeList(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'color' => 'nullable|string|max:16',
        ]);

        $list = TaskList::create([
            'name' => $data['name'],
            'color' => $data['color'] ?? '#8696a0',
            'position' => (TaskList::max('position') ?? -1) + 1,
        ]);

        $this->avisar();

        return response()->json($list, 201);
    }

    public function updateList(Request $request, TaskList $list)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:60',
            'color' => 'sometimes|string|max:16',
            'archived' => 'sometimes|boolean',
        ]);

        if (array_key_exists('archived', $data)) {
            $data['archived_at'] = $data['archived'] ? now() : null;
            unset($data['archived']);
        }

        $list->update($data);
        $this->avisar();

        return response()->json($list->fresh());
    }

    /**
     * Arquivar a lista (não apaga nada). Os cartões dela vão junto para o arquivo — é o
     * que o Trello faz e é o único jeito de não deixar cartão órfão, invisível em lista
     * nenhuma. Restaurar a lista devolve os cartões que foram arquivados com ela.
     */
    public function destroyList(TaskList $list)
    {
        // Os cartões vão marcados: `archived_with_list` é o que faz o restaurar devolver
        // exatamente estes, sem ressuscitar o que alguém tinha arquivado sozinho antes.
        $list->tasks()->noQuadro()->update(['archived_at' => now(), 'archived_with_list' => true]);
        $list->update(['archived_at' => now()]);
        $this->avisar();

        return response()->json(['message' => 'ok']);
    }

    public function restoreList(TaskList $list)
    {
        $list->update(['archived_at' => null, 'position' => (TaskList::max('position') ?? -1) + 1]);
        $list->tasks()->manual()->where('archived_with_list', true)
            ->update(['archived_at' => null, 'archived_with_list' => false]);

        $this->avisar();

        return response()->json($list->fresh());
    }

    /** Reordena as listas pela ordem em que os ids chegam. */
    public function reorderLists(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        // O where garante o escopo de empresa (CompanyScope): id de outra empresa não casa.
        foreach (array_values($data['ids']) as $i => $id) {
            TaskList::where('id', $id)->update(['position' => $i]);
        }

        $this->avisar();

        return response()->json(['message' => 'ok']);
    }

    // ----- Etiquetas -----

    public function storeLabel(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:60',
            'color' => 'required|string|max:16',
        ]);

        $label = TaskLabel::create([
            'name' => $data['name'] ?? '',
            'color' => $data['color'],
            'position' => (TaskLabel::max('position') ?? -1) + 1,
        ]);

        $this->avisar();

        return response()->json($label, 201);
    }

    public function updateLabel(Request $request, TaskLabel $label)
    {
        $data = $request->validate([
            'name' => 'sometimes|nullable|string|max:60',
            'color' => 'sometimes|string|max:16',
        ]);

        $label->update(['name' => $data['name'] ?? $label->name, 'color' => $data['color'] ?? $label->color]);
        $this->avisar();

        return response()->json($label->fresh());
    }

    public function destroyLabel(TaskLabel $label)
    {
        $label->tasks()->detach();
        $label->delete();
        $this->avisar();

        return response()->json(['message' => 'ok']);
    }

    // ----- Apoio -----

    /**
     * Empresa nova (ou base anterior ao quadro) começa sem lista nenhuma — e quadro sem
     * lista não tem onde pôr cartão. Semeia na primeira visita, uma vez só.
     */
    private function garantirPadroes(): void
    {
        if (! TaskList::exists()) {
            foreach (self::LISTAS_PADRAO as $i => [$nome, $cor]) {
                TaskList::create(['name' => $nome, 'color' => $cor, 'position' => $i]);
            }
        }

        if (! TaskLabel::exists()) {
            foreach (self::CORES_PADRAO as $i => $cor) {
                TaskLabel::create(['name' => '', 'color' => $cor, 'position' => $i]);
            }
        }
    }

    /** Time da empresa — quem pode ser responsável por um cartão. */
    private function time(Request $request): array
    {
        $companyId = $request->user()?->company_id;

        return User::when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->all();
    }

    /** Cartões no formato enxuto do quadro (o detalhe cheio é do TaskCardController). */
    private function cartoes($query): array
    {
        return $query
            ->with(['labels:id', 'members:id,name,email'])
            ->withCount(['comments', 'attachments'])
            ->withCount(['checklist as checklist_total', 'checklist as checklist_done' => fn ($q) => $q->where('done', true)])
            ->orderBy('position')->orderBy('id')
            ->get()
            ->map(fn (Task $t) => self::cartao($t))
            ->all();
    }

    public static function cartao(Task $task): array
    {
        return [
            'id' => $task->id,
            'task_list_id' => $task->task_list_id,
            'title' => $task->title,
            'description' => $task->description,
            'client' => $task->client,
            'priority' => $task->priority,
            'type' => $task->type,
            'due' => $task->due,
            'due_at' => optional($task->due_at)->toIso8601String(),
            'position' => $task->position,
            'archived_at' => optional($task->archived_at)->toIso8601String(),
            'conversation_id' => $task->conversation_id,
            'label_ids' => $task->relationLoaded('labels') ? $task->labels->pluck('id')->all() : [],
            'members' => $task->relationLoaded('members')
                ? $task->members->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all()
                : [],
            'checklist_total' => (int) ($task->checklist_total ?? 0),
            'checklist_done' => (int) ($task->checklist_done ?? 0),
            'comments_count' => (int) ($task->comments_count ?? 0),
            'attachments_count' => (int) ($task->attachments_count ?? 0),
        ];
    }

    /** Avisa as OUTRAS abas/pessoas que o quadro mudou (o front rebusca). */
    private function avisar(): void
    {
        broadcast(new CrmUpdated('board'))->toOthers();
    }
}
