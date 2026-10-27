<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketingGestorSession;
use App\Models\Message;
use App\Models\SdrAgent;
use App\Services\Sdr\Sdr;
use Illuminate\Http\Request;

/** Painel do agente SDR (/sdr). */
class SdrController extends Controller
{
    public function show()
    {
        $cfg = SdrAgent::atual();
        $sdr = new Sdr($cfg);

        return response()->json([
            'config' => $cfg,
            'enviadas_hoje' => $sdr->enviadasHoje(),
            'dentro_do_horario' => $sdr->dentroDoHorario(),
            'fila' => $sdr->filaResumo()->take(60),
            // As mensagens que ele mandou: a prova do que está sendo dito em nome da empresa.
            'mensagens' => Message::where('meta', 'sdr')->where('is_out', true)->with('conversation:id,slug,name')
                ->orderByDesc('id')->limit(40)->get(['id', 'conversation_id', 'text', 'status', 'created_at']),
            'sessoes' => MarketingGestorSession::where('agente', 'sdr')->orderByDesc('id')->limit(20)
                ->get(['id', 'agente', 'simulated', 'started_at', 'ended_at', 'status', 'ferramentas_usadas', 'resumo', 'erro']),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'max_unanswered' => 'sometimes|integer|min:1|max:4',
            'min_hours_between' => 'sometimes|integer|min:4|max:168',
            'daily_cap' => 'sometimes|integer|min:1|max:80',
            'per_turn_cap' => 'sometimes|integer|min:1|max:30',
            'instructions' => 'sometimes|nullable|string|max:20000',
        ]);
        $cfg = SdrAgent::atual();
        $cfg->update($data);

        return response()->json($cfg->fresh());
    }

    /** Um turno inteiro: o que ele pensou, que ferramenta usou e o que viu. */
    public function sessao(int $id)
    {
        return response()->json(MarketingGestorSession::where('agente', 'sdr')->findOrFail($id));
    }

    /** Pede um turno ao agendador (começa em até 5 min, em background). */
    public function rodar(Request $request)
    {
        $modo = $request->validate(['modo' => 'required|in:simular,real'])['modo'];
        $cfg = SdrAgent::atual();
        if ($modo === 'real' && ! $cfg->enabled) {
            return response()->json(['ok' => false, 'mensagem' => 'Ligue o SDR antes de rodar de verdade.'], 422);
        }
        $cfg->update(['run_requested_at' => now(), 'run_requested_mode' => $modo]);

        return response()->json(['ok' => true, 'mensagem' => 'Pedido registrado — começa em até 5 minutos.']);
    }
}
