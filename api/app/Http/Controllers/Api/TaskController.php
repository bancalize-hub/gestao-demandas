<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TaskController extends Controller
{
    public function __construct(private GoogleCalendarService $google) {}

    /**
     * Lista crua das tarefas do quadro (só o que entrou à mão — ver `scopeManual`).
     * A tela usa o `GET /board`, que devolve listas, etiquetas, time e cartões juntos;
     * este endpoint fica para quem só quer as tarefas.
     */
    public function index()
    {
        return Task::query()->noQuadro()->orderBy('position')->orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string',
            'description' => 'nullable|string',
            'client' => 'nullable|string',
            'priority' => 'nullable|in:baixa,media,alta',
            'due' => 'nullable|string|max:32',
            'due_at' => 'nullable|date',
            'type' => 'nullable|string|max:32',
            'column' => 'nullable|string|max:16',
            'task_list_id' => 'nullable|integer',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            // Portal público do cliente: identifica a empresa (tenant) destino pelo slug.
            'company_slug' => 'nullable|string',
        ]);

        $slug = $data['company_slug'] ?? null;
        unset($data['company_slug']);

        $create = function () use ($data, $request) {
            $data['column'] = $data['column'] ?? 'todo';
            $data['priority'] = $data['priority'] ?? 'media';
            // Sem lista escolhida (é o caso do formulário do cliente), o cartão entra na
            // primeira lista aberta do quadro — a que estiver mais à esquerda.
            $data['task_list_id'] = $this->listaDestino($data['task_list_id'] ?? null);
            // Solicitação nova entra no TOPO da lista, para ser vista.
            $data['position'] = (Task::query()->manual()->where('task_list_id', $data['task_list_id'])->min('position') ?? 0) - 1;

            $task = Task::create($data);
            $this->syncToCalendar($task, $request->user());

            return $task->fresh();
        };

        // Autenticado (/tasks): a empresa já está vinculada pelo middleware set.tenant.
        if ($request->user()) {
            return response()->json($create(), 201);
        }

        // Público (/solicitacoes): sem login → resolve a empresa pelo slug e cria no contexto dela.
        $company = $slug ? Company::where('slug', $slug)->where('is_active', true)->first() : null;
        abort_unless($company, 404, 'Empresa não encontrada.');

        return response()->json(app(Tenancy::class)->run($company->id, $create), 201);
    }

    public function update(Request $request, Task $task)
    {
        $data = $request->validate([
            'column' => 'nullable|string|max:16',
            'title' => 'sometimes|string',
            'description' => 'sometimes|nullable|string',
            'client' => 'sometimes|nullable|string',
            'priority' => 'sometimes|in:baixa,media,alta',
            'due' => 'sometimes|nullable|string|max:32',
            'due_at' => 'sometimes|nullable|date',
            'type' => 'sometimes|nullable|string|max:32',
            'starts_at' => 'sometimes|nullable|date',
            'ends_at' => 'sometimes|nullable|date|after_or_equal:starts_at',
        ]);

        if (! empty($data['column']) && $data['column'] !== $task->column) {
            $data['position'] = (Task::where('column', $data['column'])->max('position') ?? -1) + 1;
        }

        $task->update($data);
        $this->syncToCalendar($task, $request->user());

        return $task->fresh();
    }

    public function destroy(Request $request, Task $task)
    {
        $this->removeFromCalendar($task);
        // As linhas filhas caem por FK; o arquivo no disco não — some aqui ou fica órfão.
        foreach ($task->attachments as $anexo) {
            Storage::disk('local')->delete($anexo->path);
        }
        $task->delete();

        return response()->json(['message' => 'ok']);
    }

    /**
     * Lista em que o cartão nasce: a pedida, se existir e estiver aberta; senão a
     * primeira da esquerda. Quadro de empresa nova ainda não tem lista nenhuma — aí o
     * cartão nasce sem lista e o `GET /board` semeia as padrão na primeira visita.
     */
    private function listaDestino(?int $pedida): ?int
    {
        if ($pedida && TaskList::whereNull('archived_at')->whereKey($pedida)->exists()) {
            return $pedida;
        }

        return TaskList::whereNull('archived_at')->orderBy('position')->orderBy('id')->value('id');
    }

    /**
     * Espelha a tarefa no Google Calendar do usuário que agendou.
     * - tem horário + usuário conectado  -> cria/atualiza o evento
     * - perdeu o horário                 -> remove o evento existente
     * Best-effort: falha no Google não derruba a operação do CRM.
     */
    private function syncToCalendar(Task $task, ?User $user): void
    {
        try {
            // Deixou de ter horário: apaga o evento que existia.
            if (empty($task->starts_at)) {
                $this->removeFromCalendar($task);

                return;
            }

            // Sem usuário conectado não há onde criar/atualizar.
            $owner = $task->google_user_id ? User::find($task->google_user_id) : $user;
            if (! $owner || ! $owner->hasGoogle()) {
                return;
            }

            $start = Carbon::parse($task->starts_at);
            $end = $task->ends_at ? Carbon::parse($task->ends_at) : (clone $start)->addHour();

            $payload = [
                'title' => $task->title,
                'description' => trim('Tarefa do CRM'.($task->client ? " — {$task->client}" : '')),
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'task_id' => $task->id,
            ];

            if ($task->google_event_id) {
                $this->google->updateEvent($owner, $task->google_event_id, $payload);
            } else {
                $event = $this->google->createEvent($owner, $payload);
                // saveQuietly: não dispara nova rodada de sync nem mexe em updated_at à toa.
                $task->forceFill([
                    'google_event_id' => $event['id'],
                    'google_user_id' => $owner->id,
                ])->saveQuietly();
            }
        } catch (\Throwable $e) {
            Log::error('Sync tarefa->Google falhou', ['task' => $task->id, 'e' => $e->getMessage()]);
        }
    }

    private function removeFromCalendar(Task $task): void
    {
        if (! $task->google_event_id || ! $task->google_user_id) {
            return;
        }
        try {
            $owner = User::find($task->google_user_id);
            if ($owner && $owner->hasGoogle()) {
                $this->google->deleteEvent($owner, $task->google_event_id);
            }
            $task->forceFill(['google_event_id' => null, 'google_user_id' => null])->saveQuietly();
        } catch (\Throwable $e) {
            Log::error('Remoção tarefa->Google falhou', ['task' => $task->id, 'e' => $e->getMessage()]);
        }
    }
}
