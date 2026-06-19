<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(private GoogleCalendarService $google) {}

    private function ensureConnected(Request $request): void
    {
        abort_unless($request->user()->hasGoogle(), 409, 'Conta Google não conectada.');
    }

    /** Eventos num intervalo (?from=ISO&to=ISO). Padrão: semana atual. */
    public function index(Request $request)
    {
        $this->ensureConnected($request);

        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = isset($data['from']) ? Carbon::parse($data['from']) : Carbon::now()->startOfWeek();
        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::now()->endOfWeek();

        return response()->json([
            'events' => $this->google->listEvents($request->user(), $from, $to),
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureConnected($request);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after_or_equal:starts_at',
            'deal_id' => 'nullable|integer|exists:deals,id',
            'task_id' => 'nullable|integer|exists:tasks,id',
            'attendees' => 'sometimes|array',
            'attendees.*' => 'email',
            'add_meet' => 'sometimes|boolean',
            'conversation_slug' => 'nullable|string',
        ]);

        $event = $this->google->createEvent($request->user(), $data);
        $this->linkMeeting($request->user(), $event, $request->input('conversation_slug'));

        return response()->json($event, 201);
    }

    public function update(Request $request, string $event)
    {
        $this->ensureConnected($request);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'starts_at' => 'sometimes|date',
            'ends_at' => 'sometimes|date|after_or_equal:starts_at',
            'deal_id' => 'nullable|integer|exists:deals,id',
            'task_id' => 'nullable|integer|exists:tasks,id',
            'attendees' => 'sometimes|array',
            'attendees.*' => 'email',
            'add_meet' => 'sometimes|boolean',
            'conversation_slug' => 'nullable|string',
        ]);

        $updated = $this->google->updateEvent($request->user(), $event, $data);
        if ($request->has('conversation_slug')) {
            $this->linkMeeting($request->user(), $updated, $request->input('conversation_slug'));
        }

        return response()->json($updated);
    }

    public function destroy(Request $request, string $event)
    {
        $this->ensureConnected($request);

        $this->google->deleteEvent($request->user(), $event);

        return response()->json(['message' => 'ok']);
    }

    /**
     * Liga (ou desliga) a reunião a um lead criando/atualizando a linha em `meetings`,
     * para o card da agenda abrir a ficha e o pós-reunião (presença/resumo) processar.
     */
    private function linkMeeting(\App\Models\User $user, array $event, ?string $slug): void
    {
        if (empty($event['id'])) {
            return;
        }
        $conv = $slug ? \App\Models\Conversation::where('slug', $slug)->first() : null;
        if (! $conv) {
            // Desvincula: se havia Meeting criada por este fluxo, solta o lead.
            \App\Models\Meeting::where('google_event_id', $event['id'])->update(['conversation_id' => null]);

            return;
        }

        \App\Models\Meeting::updateOrCreate(
            ['google_event_id' => $event['id']],
            [
                'conversation_id' => $conv->id,
                'user_id' => $user->id,
                'phone' => $conv->phone,
                'title' => $event['title'] ?? 'Reunião',
                'starts_at' => ! empty($event['starts_at']) ? \Carbon\Carbon::parse($event['starts_at']) : null,
                'ends_at' => ! empty($event['ends_at']) ? \Carbon\Carbon::parse($event['ends_at']) : null,
                'meet_link' => $event['hangout_link'] ?? null,
                'reminder_lead_minutes' => (int) config('services.meeting_reminder.lead_minutes', 60),
            ]
        );
    }
}
