<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\MemoryChunk;
use App\Models\StyleProfile;
use App\Models\StyleSample;
use App\Support\Claude;
use Illuminate\Http\Request;

class MemoryController extends Controller
{
    /** Conteúdo da memória (base de conhecimento + perfil de voz). */
    public function index()
    {
        return response()->json([
            'chunks' => MemoryChunk::latest('id')->get(),
            'style' => StyleProfile::find(1)?->summary ?? '',
            'samples' => StyleSample::latest('id')->take(20)->get(),
        ]);
    }

    /** Lê a conversa com o Claude e extrai conhecimento + estilo para a memória. */
    public function memorize(Conversation $conversation)
    {
        $msgs = $conversation->messages()
            ->where('type', 'text')
            ->whereNotNull('text')
            ->orderBy('position')
            ->get(['is_out', 'text']);

        if ($msgs->isEmpty()) {
            return response()->json(['message' => 'Conversa sem texto para aprender.'], 422);
        }

        $transcript = $msgs->map(fn ($m) => ($m->is_out ? 'Atendente' : 'Cliente').': '.$m->text)->implode("\n");
        $outbound = $msgs->where('is_out', true)->pluck('text')->implode("\n");

        $addedChunks = $this->extractKnowledge($transcript, $conversation->id);
        $this->extractStyle($outbound, $conversation->id);

        $conversation->update(['in_memory' => true]);

        return response()->json(['added_chunks' => $addedChunks, 'in_memory' => true]);
    }

    private function extractKnowledge(string $transcript, int $convId): int
    {
        $prompt = <<<TXT
        Você analisa uma conversa de atendimento para extrair CONHECIMENTO reutilizável que ajude a responder futuros clientes.
        Considere SOMENTE o que o ATENDENTE (nossa empresa) afirmou — preços, políticas, respostas a dúvidas, contorno de objeções, procedimentos. Ignore afirmações do cliente.

        Conversa:
        {$transcript}

        Responda APENAS um array JSON (sem texto fora dele):
        [{"kind":"faq|preco|objecao|procedimento|fato","gatilho":"tema/pergunta curto","conteudo":"resposta em 1-3 frases","keywords":"palavras-chave separadas por vírgula"}]
        Se não houver nada reutilizável, responda [].
        TXT;

        $items = Claude::json(Claude::run($prompt));
        if (! is_array($items)) {
            return 0;
        }

        $added = 0;
        foreach ($items as $it) {
            $gatilho = trim((string) ($it['gatilho'] ?? ''));
            $conteudo = trim((string) ($it['conteudo'] ?? ''));
            if ($gatilho === '' || $conteudo === '') {
                continue;
            }
            // dedup simples por gatilho
            if (MemoryChunk::whereRaw('LOWER(gatilho) = ?', [mb_strtolower($gatilho)])->exists()) {
                continue;
            }
            MemoryChunk::create([
                'kind' => $it['kind'] ?? 'faq',
                'gatilho' => mb_substr($gatilho, 0, 255),
                'conteudo' => mb_substr($conteudo, 0, 2000),
                'keywords' => mb_substr((string) ($it['keywords'] ?? ''), 0, 255),
                'conversation_id' => $convId,
            ]);
            $added++;
        }

        return $added;
    }

    private function extractStyle(string $outbound, int $convId): void
    {
        if (trim($outbound) === '') {
            return;
        }

        $current = StyleProfile::find(1)?->summary ?? '(vazio)';

        $prompt = <<<TXT
        Você mantém um GUIA DE VOZ de um atendente, para clonar o jeito dele escrever.

        Guia atual:
        {$current}

        Novas mensagens DO ATENDENTE:
        {$outbound}

        Atualize o guia incorporando o novo material. Descreva tom, formalidade, saudações, despedidas, uso de emoji, tamanho de frase e expressões típicas. Máximo ~200 palavras.
        Responda APENAS um JSON: {"summary":"<guia atualizado em markdown>","samples":["até 3 mensagens reais e representativas do atendente"]}
        TXT;

        $data = Claude::json(Claude::run($prompt));
        if (! is_array($data)) {
            return;
        }

        if (! empty($data['summary'])) {
            StyleProfile::updateOrCreate(['id' => 1], ['summary' => mb_substr((string) $data['summary'], 0, 8000)]);
        }
        foreach (array_slice($data['samples'] ?? [], 0, 3) as $s) {
            $s = trim((string) $s);
            if ($s !== '' && ! StyleSample::where('text', $s)->exists()) {
                StyleSample::create(['text' => mb_substr($s, 0, 1000), 'conversation_id' => $convId]);
            }
        }
        // mantém no máximo ~30 exemplos
        $ids = StyleSample::latest('id')->skip(30)->take(1000)->pluck('id');
        if ($ids->isNotEmpty()) {
            StyleSample::whereIn('id', $ids)->delete();
        }
    }

    public function updateChunk(Request $request, MemoryChunk $memoryChunk)
    {
        $memoryChunk->update($request->only(['kind', 'gatilho', 'conteudo', 'keywords']));

        return response()->json($memoryChunk);
    }

    public function destroyChunk(MemoryChunk $memoryChunk)
    {
        $memoryChunk->delete();

        return response()->json(['message' => 'ok']);
    }

    public function updateStyle(Request $request)
    {
        $data = $request->validate(['summary' => 'nullable|string']);
        StyleProfile::updateOrCreate(['id' => 1], ['summary' => $data['summary'] ?? '']);

        return response()->json(['message' => 'ok']);
    }
}
