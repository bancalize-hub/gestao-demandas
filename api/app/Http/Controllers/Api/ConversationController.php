<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class ConversationController extends Controller
{
    public function index()
    {
        return Conversation::with('messages')->orderBy('position')->get();
    }

    public function update(Request $request, Conversation $conversation)
    {
        $conversation->update($request->only([
            'unread', 'preview', 'time', 'stage', 'stage_color', 'prob', 'hot', 'online',
        ]));

        return $conversation->load('messages');
    }

    /**
     * Gera a sugestão de próxima resposta do atendente via Claude Code (assinatura).
     * Roda o CLI `claude` como subprocesso, autenticado pelo OAuth token — sem custo de API.
     */
    public function suggestReply(Conversation $conversation)
    {
        $token = config('services.claude.oauth_token');
        if (! $token) {
            return response()->json(['message' => 'Claude não configurado'], 503);
        }

        $transcript = $conversation->messages()
            ->where('type', 'text')
            ->whereNotNull('text')
            ->orderBy('id')
            ->get(['is_out', 'text'])
            ->map(fn ($m) => ($m->is_out ? 'Atendente' : $conversation->name).': '.$m->text)
            ->implode("\n");

        if ($transcript === '') {
            $transcript = '(sem mensagens ainda — o lead acabou de iniciar a conversa)';
        }

        $prompt = <<<TXT
        Você é um assistente de vendas ajudando um atendente a responder um lead pelo WhatsApp.
        Lead: {$conversation->name} ({$conversation->role}). Estágio do negócio: {$conversation->stage}.

        Conversa até agora (Atendente = nossa empresa; {$conversation->name} = lead):
        {$transcript}

        Escreva APENAS a próxima mensagem que o Atendente deve enviar. Regras:
        - Português do Brasil, tom cordial e comercial.
        - No máximo 2 frases curtas.
        - Sem aspas, sem rótulos, sem explicações — devolva só o texto da mensagem.
        TXT;

        $result = Process::timeout(60)
            ->env([
                'CLAUDE_CODE_OAUTH_TOKEN' => $token,
                'HOME' => storage_path('app/claude-home'),
            ])
            ->run([config('services.claude.bin'), '-p', $prompt]);

        if (! $result->successful()) {
            return response()->json(['message' => 'Falha ao gerar sugestão'], 502);
        }

        return response()->json(['suggestion' => trim($result->output())]);
    }
}
