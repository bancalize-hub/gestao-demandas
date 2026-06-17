<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\MemoryChunk;
use App\Models\StyleProfile;
use App\Models\StyleSample;
use App\Support\Claude;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ConversationController extends Controller
{
    public function index()
    {
        return Conversation::with('messages')->orderBy('position')->get();
    }

    public function update(Request $request, Conversation $conversation)
    {
        $conversation->update($request->only([
            'unread', 'preview', 'time', 'stage', 'stage_color', 'prob', 'hot', 'online', 'archived', 'tags',
        ]));

        return $conversation->load('messages');
    }

    /** Sugestão de próxima resposta — no seu estilo e usando a memória (Claude/assinatura). */
    public function suggestReply(Conversation $conversation)
    {
        $reply = $this->generateReply($conversation);
        if ($reply === null) {
            return response()->json(['message' => 'Falha ao gerar sugestão'], 502);
        }

        return response()->json(['suggestion' => $reply]);
    }

    /** Monta o prompt (voz + conhecimento + histórico) e gera a resposta. */
    private function generateReply(Conversation $conversation): ?string
    {
        if (! config('services.claude.oauth_token')) {
            return null;
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

        $context = $this->memoryContext($conversation);

        $prompt = <<<TXT
        Você é o ATENDENTE escrevendo a próxima mensagem para um lead no WhatsApp.
        Lead: {$conversation->name}. Estágio: {$conversation->stage}.

        {$context}
        Conversa (Atendente = você; {$conversation->name} = lead):
        {$transcript}

        Escreva APENAS a próxima mensagem do Atendente. Regras:
        - Use EXATAMENTE o estilo/voz descrito acima (se houver).
        - Use o conhecimento acima quando fizer sentido; nunca invente preços/políticas.
        - Português do Brasil, no máximo 2-3 frases curtas.
        - Sem aspas, sem rótulos — só o texto da mensagem.
        TXT;

        $out = Claude::run($prompt, 60);

        return $out !== null ? trim($out) : null;
    }

    /** Bloco de contexto: perfil de voz + exemplos + conhecimento relevante. */
    private function memoryContext(Conversation $conversation): string
    {
        $ctx = '';

        $style = StyleProfile::find(1)?->summary;
        if ($style) {
            $ctx .= "COMO VOCÊ (atendente) FALA:\n{$style}\n\n";
        }

        $samples = StyleSample::latest('id')->take(4)->pluck('text');
        if ($samples->isNotEmpty()) {
            $ctx .= "EXEMPLOS DE MENSAGENS SUAS:\n- ".$samples->implode("\n- ")."\n\n";
        }

        $chunks = $this->relevantChunks($conversation);
        if ($chunks->isNotEmpty()) {
            $ctx .= "CONHECIMENTO (use quando relevante):\n";
            foreach ($chunks as $c) {
                $ctx .= "- [{$c->kind}] {$c->gatilho}: {$c->conteudo}\n";
            }
            $ctx .= "\n";
        }

        return $ctx;
    }

    /** Recupera os chunks mais relevantes por palavra-chave nas últimas mensagens do lead. */
    private function relevantChunks(Conversation $conversation): Collection
    {
        $recent = $conversation->messages()
            ->where('type', 'text')->where('is_out', false)->whereNotNull('text')
            ->orderByDesc('id')->take(3)->pluck('text')->implode(' ');

        $words = collect(preg_split('/\W+/u', mb_strtolower($recent)))
            ->filter(fn ($w) => mb_strlen($w) >= 4)->unique();

        $all = MemoryChunk::get();
        if ($words->isEmpty() || $all->isEmpty()) {
            return $all->take(5);
        }

        return $all->map(function ($c) use ($words) {
            $hay = mb_strtolower(($c->keywords ?? '').' '.$c->gatilho);
            $c->score = $words->filter(fn ($w) => str_contains($hay, $w))->count();

            return $c;
        })->filter(fn ($c) => $c->score > 0)->sortByDesc('score')->take(5)->values();
    }
}
