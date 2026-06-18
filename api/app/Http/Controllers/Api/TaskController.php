<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TaskController extends Controller
{
    public function __construct(private GoogleCalendarService $google) {}

    public function index()
    {
        return Task::orderBy('position')->orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string',
            'client' => 'nullable|string',
            'priority' => 'nullable|in:baixa,media,alta',
            'due' => 'nullable|string|max:32',
            'type' => 'nullable|string|max:32',
            'column' => 'nullable|string|max:16',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);

        $data['column'] = $data['column'] ?? 'todo';
        $data['priority'] = $data['priority'] ?? 'media';
        // Novas solicitações entram no topo de "A fazer".
        $data['position'] = (Task::where('column', $data['column'])->min('position') ?? 0) - 1;

        $task = Task::create($data);
        $this->syncToCalendar($task, $request->user());

        return response()->json($task->fresh(), 201);
    }

    public function update(Request $request, Task $task)
    {
        $data = $request->validate([
            'column' => 'nullable|string|max:16',
            'title' => 'nullable|string',
            'client' => 'nullable|string',
            'priority' => 'nullable|in:baixa,media,alta',
            'due' => 'nullable|string|max:32',
            'type' => 'nullable|string|max:32',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
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
        $task->delete();

        return response()->json(['message' => 'ok']);
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
