<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\LeadActivity;
use Illuminate\Http\Request;

class LeadActivityController extends Controller
{
    /** Linha do tempo do lead, mais recente primeiro. */
    public function index(Conversation $conversation)
    {
        return $conversation->activities()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /** Nota manual (ou outro tipo) registrada pelo atendente. */
    public function store(Request $request, Conversation $conversation)
    {
        $data = $request->validate([
            'type' => 'nullable|string|max:24',
            'title' => 'required|string|max:200',
            'body' => 'nullable|string|max:5000',
        ]);

        $activity = LeadActivity::create([
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()?->id,
            'type' => $data['type'] ?? 'nota',
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'occurred_at' => now(),
        ]);

        return response()->json($activity->load('user'), 201);
    }

    public function destroy(Conversation $conversation, LeadActivity $activity)
    {
        abort_unless($activity->conversation_id === $conversation->id, 404);
        $activity->delete();

        return response()->json(['message' => 'ok']);
    }
}
