<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\MemoryChunk;
use App\Models\StyleProfile;
use App\Models\StyleRule;
use App\Models\StyleSample;
use App\Support\Claude;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MemoryController extends Controller
{
    /** Conteúdo da memória (base de conhecimento + perfil de voz). */
    public function index()
    {
        return response()->json([
            'chunks' => MemoryChunk::latest('id')->get(),
            'style' => StyleProfile::first()?->summary ?? '',
            'samples' => StyleSample::latest('id')->take(20)->get(),
            'rules' => StyleRule::orderBy('id')->get(),
        ]);
    }

    /** Salva uma correção do atendente como regra (Claude generaliza). */
    public function storeRule(Request $request)
    {
        $instruction = trim($request->validate(['instruction' => 'required|string'])['instruction']);

        $prompt = "O atendente deu este ajuste a uma resposta sugerida: \"{$instruction}\".\n"
            ."Transforme em UMA regra curta, geral e reutilizável (imperativo, 1 frase) de como ele quer que as respostas sejam.\n"
            .'Responda APENAS a regra, sem aspas.';

        $rule = Claude::run($prompt, 40);
        $rule = $rule ? trim($rule) : $instruction;

        return response()->json(StyleRule::create(['rule' => mb_substr($rule, 0, 400)]), 201);
    }

    public function destroyRule(StyleRule $styleRule)
    {
        $styleRule->delete();

        return response()->json(['message' => 'ok']);
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

        $current = StyleProfile::first()?->summary ?? '(vazio)';

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
            // Um perfil de voz POR EMPRESA. O CompanyScope isola por tenant e o
            // BelongsToCompany carimba company_id na criação — NÃO usar id fixo (=1),
            // que colidia com a PK do perfil de outra empresa.
            $profile = StyleProfile::firstOrNew([]);
            $profile->summary = mb_substr((string) $data['summary'], 0, 8000);
            $profile->save();
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

    /** Cria um conhecimento manualmente pela tela de Memória. */
    public function storeChunk(Request $request)
    {
        $data = $this->validateChunk($request);

        return response()->json(MemoryChunk::create($data), 201);
    }

    public function updateChunk(Request $request, MemoryChunk $memoryChunk)
    {
        $memoryChunk->update($this->validateChunk($request));

        return response()->json($memoryChunk);
    }

    private function validateChunk(Request $request): array
    {
        return $request->validate([
            'kind' => 'required|in:faq,preco,objecao,procedimento,fato',
            'gatilho' => 'required|string|max:255',
            'conteudo' => 'required|string|max:2000',
            'keywords' => 'nullable|string|max:255',
        ]);
    }

    public function destroyChunk(MemoryChunk $memoryChunk)
    {
        $memoryChunk->delete();

        return response()->json(['message' => 'ok']);
    }

    public function updateStyle(Request $request)
    {
        $data = $request->validate(['summary' => 'nullable|string']);
        // Perfil de voz por empresa (ver extractStyle): sem id fixo, deixa o
        // CompanyScope/BelongsToCompany isolar e carimbar o tenant.
        $profile = StyleProfile::firstOrNew([]);
        $profile->summary = $data['summary'] ?? '';
        $profile->save();

        return response()->json(['message' => 'ok']);
    }
}
