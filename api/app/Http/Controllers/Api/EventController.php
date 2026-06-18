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
        ]);

        return response()->json($this->google->createEvent($request->user(), $data), 201);
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
        ]);

        return response()->json($this->google->updateEvent($request->user(), $event, $data));
    }

    public function destroy(Request $request, string $event)
    {
        $this->ensureConnected($request);

        $this->google->deleteEvent($request->user(), $event);

        return response()->json(['message' => 'ok']);
    }
}
