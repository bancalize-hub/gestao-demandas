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

        $primeira = ! $session->messages()->where('role', 'user')->exists();
        $session->messages()->create(['role' => 'user', 'content' => $data['prompt']]);

        // Lista com cinco "Nova sessão" não diz nada: o primeiro pedido vira o título.
        if ($primeira && in_array($session->title, ['Nova sessão', '', null], true)) {
            $titulo = trim(preg_replace('/\s+/', ' ', $data['prompt']) ?? '');
            $session->title = mb_strimwidth($titulo, 0, 60, '…');
        }
        $session->touch();
        $session->save();

        $job = $session->jobs()->create([
            'prompt' => $data['prompt'],
            'status' => 'pending',
        ]);

        return response()->json(['job_id' => $job->id], 201);
    }

    /**
     * Polling do andamento/saída de um job.
     *
     * `from` = quantos BYTES a tela já tem. Sem isso, cada volta de 1s re-baixava a saída
     * inteira: uma execução longa vira dezenas de MB de tráfego (e de JSON encode) à toa.
     */
    public function job(Request $request, AgentJob $job)
    {
        $output = (string) ($job->output ?? '');
        $from = (int) $request->query('from', 0);
        $from = max(0, min($from, strlen($output)));
        $chunk = substr($output, $from);

        // O worker grava blocos de 8KB e pode partir um caractere UTF-8 no meio; entregar
        // esse pedaço quebrado deixaria lixo permanente no acumulado da tela.
        for ($i = 0; $i < 4 && $chunk !== '' && ! mb_check_encoding($chunk, 'UTF-8'); $i++) {
            $chunk = substr($chunk, 0, -1);
        }

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'chunk' => $chunk,
            'len' => $from + strlen($chunk),
            'error' => $job->error,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
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
