<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentJob;
use App\Models\AgentSession;
use Illuminate\Http\Request;

/**
 * Agente operacional: chat que dirige o Claude Code na VPS (usuário gestao-agent,
 * --dangerously-skip-permissions) para editar o próprio sistema e operar o servidor.
 * Tudo aqui exige login (auth:sanctum) e fica registrado (auditoria) nas tabelas agent_*.
 */
class AgentController extends Controller
{
    public function index()
    {
        return AgentSession::orderByDesc('updated_at')->get(['id', 'title', 'cwd', 'updated_at']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:120',
            'cwd' => 'nullable|string|max:300',
        ]);

        return AgentSession::create([
            'title' => $data['title'] ?? 'Nova sessão',
            'cwd' => $data['cwd'] ?? '/var/www/gestao',
        ]);
    }

    public function show(AgentSession $session)
    {
        $session->load('messages');
        // Job ainda em andamento? Devolve o id para a tela RETOMAR o streaming ao reabrir a sessão.
        $active = $session->jobs()->whereIn('status', ['pending', 'running'])->orderByDesc('id')->first();

        return [
            'id' => $session->id,
            'messages' => $session->messages,
            'active_job_id' => $active?->id,
        ];
    }

    public function destroy(AgentSession $session)
    {
        $session->delete();

        return response()->json(['ok' => true]);
    }

    /** Recebe uma instrução do usuário → registra (auditoria) e enfileira um job p/ o worker. */
    public function message(Request $request, AgentSession $session)
    {
        $data = $request->validate(['prompt' => 'required|string']);

        // Não deixa enfileirar em cima de um job ainda rodando nesta sessão.
        $busy = $session->jobs()->whereIn('status', ['pending', 'running'])->exists();
        if ($busy) {
            return response()->json(['message' => 'O agente ainda está executando o pedido anterior.'], 409);
        }

        $session->messages()->create(['role' => 'user', 'content' => $data['prompt']]);
        $session->touch();

        $job = $session->jobs()->create([
            'prompt' => $data['prompt'],
            'status' => 'pending',
        ]);

        return response()->json(['job_id' => $job->id], 201);
    }

    /** Polling do andamento/saída de um job (streaming simples). */
    public function job(AgentJob $job)
    {
        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'output' => $job->output ?? '',
            'error' => $job->error,
        ]);
    }

    /** Botão de matar: pede o cancelamento; o worker derruba o processo. */
    public function stop(AgentJob $job)
    {
        if (in_array($job->status, ['pending', 'running'], true)) {
            $job->update(['cancel_requested' => true]);
        }

        return response()->json(['ok' => true]);
    }
}
