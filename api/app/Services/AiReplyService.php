<?php

namespace App\Services;

use App\Models\ChatTab;
use App\Models\Conversation;
use App\Models\Material;
use App\Models\MemoryChunk;
use App\Models\Stage;
use App\Models\StyleProfile;
use App\Models\StyleRule;
use App\Models\StyleSample;
use App\Models\User;
use App\Support\Claude;
use Illuminate\Support\Collection;

/**
 * Gera a próxima mensagem do atendente para um lead — no estilo/voz configurado,
 * usando a memória (perfil, regras, exemplos, conhecimento) e o objetivo da etapa.
 * Compartilhado entre a sugestão manual (botão "Gerar") e o atendimento automático.
 */
class AiReplyService
{
    /**
     * Palavras que não dizem nada sobre o assunto. Precisam sair ANTES da pontuação:
     * como o conhecimento é curto e curado, "como"/"quero"/"preciso" são RAROS nele —
     * a pontuação por raridade dava a elas o maior peso e "como faço X" caía em qualquer
     * conhecimento que tivesse "como" no título.
     */
    private const VAZIAS = [
        'como', 'quero', 'queria', 'preciso', 'precisa', 'onde', 'quando', 'qual', 'quais', 'porque',
        'para', 'pelo', 'pela', 'esta', 'este', 'esse', 'essa', 'isso', 'aqui', 'ainda', 'agora',
        'meus', 'minha', 'minhas', 'nosso', 'nossa', 'seus', 'suas', 'voce', 'voces', 'tenho', 'temos',
        'fazer', 'faco', 'faca', 'consigo', 'posso', 'pode', 'poderia', 'deve', 'devo', 'tudo', 'tipo',
        'sobre', 'mais', 'menos', 'muito', 'depois', 'antes', 'entao', 'mesmo', 'tambem', 'sendo',
        'obrigado', 'favor', 'gente', 'dia', 'tarde', 'noite', 'certo', 'ficou', 'ficar', 'sabe',
        'estou', 'estava', 'seria', 'teria', 'nada', 'algum', 'alguma', 'todo', 'toda', 'cada',
    ];

    /** Monta o prompt (voz + conhecimento + histórico) e gera a resposta. Retorna null se indisponível. */
    public function generate(Conversation $conversation, ?string $instruction = null, ?string $previous = null): ?string
    {
        // Token pode vir do painel (arquivo) ou do .env — Claude::token() resolve os dois.
        if (! Claude::token()) {
            return null;
        }

        // Só as últimas mensagens: conversa antiga inteira (1000+ msgs) não cabe no
        // contexto útil e deixava o prompt gigante à toa. As últimas 80 bastam.
        $transcript = $conversation->messages()
            ->where(function ($q) {
                $q->where(fn ($t) => $t->where('type', 'text')->whereNotNull('text'))
                    ->orWhere(fn ($v) => $v->where('type', 'voice')->whereNotNull('transcript')->where('transcript', '!=', ''))
                    ->orWhere('type', 'image');
            })
            ->reorder()->orderByRaw('ts IS NULL DESC, ts DESC')->orderByDesc('id')
            ->take(80)
            ->get(['is_out', 'type', 'text', 'transcript'])
            ->reverse()
            ->values()
            ->map(function ($m) use ($conversation) {
                $who = $m->is_out ? 'Atendente' : $conversation->name;
                $content = match ($m->type) {
                    'voice' => '[áudio do cliente] '.$m->transcript,
                    'image' => self::imageLine($m),
                    default => $m->text,
                };

                return $who.': '.$content;
            })
            ->implode("\n");

        if ($transcript === '') {
            $transcript = '(sem mensagens ainda — o lead acabou de iniciar a conversa)';
        }

        $context = $this->memoryContext($conversation);

        // Objetivo: o do TIME dono da etapa (SDR/Closer/CS) e, se houver, o da etapa
        // específica. Um lead em "Negociação" não pode ser tratado como um lead novo.
        $stage = Stage::where('key', $conversation->stage)->first();
        $stageName = $stage->name ?? $conversation->stage;
        $team = ChatTab::forStage($conversation->stage);

        $objetivo = '';
        if ($team && trim((string) $team->objetivo) !== '') {
            $objetivo .= "SEU PAPEL AGORA ({$team->name}):\n{$team->objetivo}\n\n";
        }
        if ($stage && trim((string) $stage->goal) !== '') {
            $objetivo .= "OBJETIVO NESTA ETAPA DO FUNIL (\"{$stageName}\"):\n{$stage->goal}\n\n";
        }
        if ($objetivo !== '') {
            $objetivo .= 'Conduza a conversa de forma sutil e natural rumo a esse objetivo — sem ser insistente, '
                ."sem forçar e sem soar robótico. Só avance quando fizer sentido no contexto.\n\n";
        }

        // Data/hora atual (horário de Brasília) + saudação correta, para a IA não errar
        // "bom dia/boa tarde/boa noite" (ex.: dizer "boa tarde" às 2 da madrugada).
        $now = now();
        $hour = (int) $now->format('G');
        $saudacao = $hour >= 5 && $hour < 12 ? 'Bom dia' : ($hour >= 12 && $hour < 18 ? 'Boa tarde' : 'Boa noite');
        $diasSemana = [
            'Sunday' => 'domingo', 'Monday' => 'segunda-feira', 'Tuesday' => 'terça-feira',
            'Wednesday' => 'quarta-feira', 'Thursday' => 'quinta-feira', 'Friday' => 'sexta-feira', 'Saturday' => 'sábado',
        ];
        $diaSemana = $diasSemana[$now->format('l')] ?? '';
        $agora = "AGORA: {$diaSemana}, {$now->format('d/m/Y')} às {$now->format('H:i')} (horário de Brasília).\n"
            ."Se for cumprimentar, a saudação correta para este horário é \"{$saudacao}\". "
            ."NUNCA use uma saudação que não combine com a hora atual.\n\n";

        // Horários REAIS livres da agenda — para a IA propor reunião sem inventar data/hora.
        // Best-effort: se não houver Google conectado ou a API falhar, ela só pergunta a preferência.
        $agendaBlock = '';
        $agendaRule = '- Para marcar reunião, pergunte ao lead qual dia/horário ele prefere. NUNCA invente datas ou horários específicos.';
        try {
            // TODAS as agendas conectadas DESTA empresa: os horários oferecidos têm de ser os
            // mesmos que o agendador consegue marcar, e ele já trabalha com o time inteiro —
            // com dois anfitriões, oferecer só a agenda de um esconderia metade da capacidade.
            // O filtro por empresa não é decorativo: `User` não tem escopo de tenant, então
            // sem ele a IA de uma empresa proporia o horário livre da agenda de outra.
            $hosts = User::where('company_id', $conversation->company_id)
                ->whereNotNull('google_refresh_token')->get();
            if ($hosts->isNotEmpty()) {
                $slots = app(GoogleCalendarService::class)->freeSlotsForHosts($hosts, 60);
                if ($slots) {
                    $list = collect($slots)->take(8)->map(fn ($s) => '- '.$s['label'])->implode("\n");
                    $agendaBlock = "HORÁRIOS REAIS LIVRES NA AGENDA (são os ÚNICOS disponíveis; reunião dura 1 hora):\n{$list}\n\n";
                    $agendaRule = '- Ao propor reunião, ofereça 2 ou 3 dos HORÁRIOS REAIS LIVRES listados acima, copiando exatamente (dia e hora). NUNCA invente nem ofereça datas/horários fora dessa lista. Cada reunião dura 1 hora.';
                }
            }
        } catch (\Throwable $e) {
            // sem agenda disponível → mantém a regra de perguntar a preferência
        }

        // Anti-insistência. O objetivo do time é "agendar a reunião", e o modelo fecha
        // TODA mensagem com um convite — o lead pergunta preço, adquirente, maquininha, e
        // leva três "vamos marcar uma call?" seguidos. Regra determinística: se as nossas
        // últimas mensagens já convidaram e o lead não tocou no assunto, esta responde só
        // a dúvida dele.
        $ultimasNossas = $conversation->messages()
            ->where('is_out', true)->where('type', 'text')->whereNotNull('text')
            ->reorder()->orderByDesc('ts')->orderByDesc('id')->take(2)->pluck('text');
        $ultimaDoLead = (string) $conversation->messages()
            ->where('is_out', false)
            ->reorder()->orderByDesc('ts')->orderByDesc('id')
            ->value('text');

        $convite = '/\b(call|reuni[õoãa]|agend|hor[áa]rio|meet|apresenta[çc])/iu';
        $aceite = '/\b(pode ser|topo|bora|vamos|fechado|amanh[ãa]|hoje|segunda|ter[çc]a|quarta|quinta|sexta|\d{1,2}\s*h|\d{1,2}:\d{2})/iu';

        $jaConvidou = $ultimasNossas->contains(fn ($t) => (bool) preg_match($convite, (string) $t));
        $leadTocouNoAssunto = (bool) (preg_match($convite, $ultimaDoLead) || preg_match($aceite, $ultimaDoLead));

        $regraConvite = ($jaConvidou && ! $leadTocouNoAssunto)
            ? '- Você JÁ convidou para a reunião e o lead não respondeu sobre isso. NÃO convide de novo nesta mensagem: '
                .'fique no assunto que ele levantou e pare por aí. Insistir a cada mensagem afasta o lead.'
            : '- Se fizer sentido, convide para a reunião UMA vez — nunca em duas mensagens seguidas.';

        // Materiais (PDF etc.): a IA recebe a lista com o "quando" de cada um e decide se
        // algum ajuda AGORA — em vez de a configuração ter que adivinhar o momento certo.
        $materiais = $this->materiaisDisponiveis($team?->id);
        $materialBlock = '';
        $regraMaterial = '';
        if ($materiais->isNotEmpty()) {
            $lista = $materiais
                ->map(fn ($m) => "- #{$m->id} \"{$m->name}\"".(trim((string) $m->quando) !== '' ? ' — enviar quando: '.$m->quando : ''))
                ->implode("\n");
            $materialBlock = "MATERIAIS QUE VOCÊ PODE ENVIAR:\n{$lista}\n\n";
            $regraMaterial = '- Se (e SÓ se) um dos materiais acima ajudar exatamente agora, acrescente no FIM uma última linha isolada '
                .'no formato [MATERIAL: #id]. Nunca cite essa linha no texto, nunca mande mais de um, e não mande material '
                ."que você já enviou nesta conversa.\n";
        }

        // Três modos: reescrever uma mensagem com o ajuste pedido, escrever sob uma instrução
        // específica (follow-up, retomada ativa) ou simplesmente responder o lead.
        // Instrução sem `previous` era ignorada em silêncio — o follow-up caía na genérica.
        $task = match (true) {
            $instruction && $previous => "Você ia mandar esta mensagem:\n\"{$previous}\"\n\nReescreva-a aplicando este ajuste pedido pelo atendente: \"{$instruction}\". Mantenha o estilo, as regras, o conhecimento e o objetivo da etapa.",
            (bool) $instruction => $instruction,
            default => 'Escreva a próxima mensagem do Atendente.',
        };

        $prompt = <<<TXT
        Você é o ATENDENTE escrevendo a próxima mensagem para um lead no WhatsApp.
        Lead: {$conversation->name}. Etapa do funil: {$stageName}.

        {$agora}{$objetivo}{$context}{$materialBlock}{$agendaBlock}
        Conversa (Atendente = você; {$conversation->name} = lead):
        {$transcript}

        {$task}
        Regras de saída:
        - Use EXATAMENTE o estilo/voz e as regras descritas acima (se houver).
        - Use o conhecimento acima quando fizer sentido; nunca invente preços/políticas.
        - Respeite SEU PAPEL e persiga o OBJETIVO (se houver) de forma sutil, no ritmo da conversa.
        {$regraConvite}
        {$regraMaterial}{$agendaRule}
        - NUNCA diga que enviou o convite, que marcou/agendou a reunião nem que "está confirmado/agendado":
          a confirmação real (com o link do Meet) é enviada automaticamente pelo sistema, não por você.
        - Só cumprimente ("{$saudacao}") no início da conversa ou após uma longa pausa; ao saudar, respeite o horário atual indicado acima.
        - Português do Brasil, no máximo 2-3 frases curtas.
        - Sem aspas, sem rótulos — só o texto da mensagem.
        TXT;

        $out = Claude::run($prompt, 60);

        return $out !== null ? trim($out) : null;
    }

    /** Materiais ativos que este time pode enviar (sem time = de todos). */
    public function materiaisDisponiveis(?int $teamId): Collection
    {
        return Material::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('chat_tab_id')->when($teamId, fn ($w) => $w->orWhere('chat_tab_id', $teamId)))
            ->orderBy('id')->get();
    }

    /**
     * Separa o marcador [MATERIAL: #id] do texto da resposta.
     * Devolve [texto limpo, material ou null] — quem envia decide o que fazer com o anexo.
     */
    public static function extrairMaterial(string $reply): array
    {
        if (! preg_match('/\[\s*MATERIAL\s*:\s*#?(\d+)\s*\]/i', $reply, $m)) {
            return [$reply, null];
        }

        $texto = trim(preg_replace('/\[\s*MATERIAL\s*:\s*#?\d+\s*\]/i', '', $reply) ?? $reply);

        return [$texto, Material::where('is_active', true)->find((int) $m[1])];
    }

    /** Linha do histórico para uma imagem: descrição da visão (se houver) + legenda. */
    private static function imageLine($m): string
    {
        $desc = trim((string) $m->transcript);
        $line = '[enviou uma imagem'.($desc !== '' ? ' — conteúdo: '.$desc : '').']';
        $caption = trim((string) $m->text);

        return $caption !== '' ? $line.' '.$caption : $line;
    }

    /** Bloco de contexto: perfil de voz + exemplos + conhecimento relevante. */
    public function memoryContext(Conversation $conversation): string
    {
        $ctx = '';

        $style = StyleProfile::first()?->summary;
        if ($style) {
            $ctx .= "COMO VOCÊ (atendente) FALA:\n{$style}\n\n";
        }

        $rules = StyleRule::orderBy('id')->pluck('rule');
        if ($rules->isNotEmpty()) {
            $ctx .= "REGRAS QUE VOCÊ SEMPRE SEGUE:\n- ".$rules->implode("\n- ")."\n\n";
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

    /**
     * Recupera os chunks mais relevantes por palavra-chave nas últimas mensagens do lead,
     * dentro do que o TIME dono da etapa pode usar (conhecimento sem time = de todos).
     */
    private function relevantChunks(Conversation $conversation): Collection
    {
        $recent = $conversation->messages()
            ->where('is_out', false)
            ->where(fn ($q) => $q->whereNotNull('text')->orWhereNotNull('transcript'))
            ->reorder()->orderByDesc('ts')->orderByDesc('id')->take(3)
            ->get(['text', 'transcript'])
            ->map(fn ($m) => trim(($m->text ?? '').' '.($m->transcript ?? '')))
            ->implode(' ');

        // Sem acento dos dois lados: no WhatsApp o cliente escreve "usuario", "e-mail nao
        // chega", "antecipacao" — com acento no cadastro e sem acento na pergunta, nada casava.
        $texto = self::normalizar($recent);
        $words = collect(preg_split('/\W+/u', $texto))
            ->filter(fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, self::VAZIAS, true))
            ->unique();

        // O playbook do TIME (como o SDR/Closer/CS atende) entra SEMPRE: é sobre o nosso
        // comportamento, não sobre o que o lead escreveu — depender de palavra-chave fazia
        // ele quase nunca aparecer. O conhecimento geral (produto, preço, objeções) segue
        // por relevância, que é o que evita despejar 60 chunks no prompt.
        //
        // Os TUTORIAIS são a exceção dentro do time: são dezenas (uma tela do sistema cada) e
        // mudam de assunto a cada pergunta do cliente. Entrassem por data, como o playbook, só
        // os 8 últimos existiriam para a IA — quem perguntasse da logo receberia o tutorial de
        // maquininha. Por isso eles disputam por palavra-chave, igual ao conhecimento geral.
        $teamId = ChatTab::forStage($conversation->stage)?->id;
        $team = $teamId
            ? MemoryChunk::where('chat_tab_id', $teamId)->where('kind', '!=', 'tutorial')->latest('id')->take(8)->get()
            : collect();
        $tutoriais = $teamId
            ? MemoryChunk::where('chat_tab_id', $teamId)->where('kind', 'tutorial')->get()
            : collect();

        $geral = MemoryChunk::whereNull('chat_tab_id')->get();
        if ($words->isEmpty() || ($geral->isEmpty() && $tutoriais->isEmpty())) {
            return $team->concat($geral->take(5))->values();
        }

        $pontuar = fn (Collection $chunks, int $limite) => $this->pontuarPorRelevancia($chunks, $words, $texto, $limite);

        // Tutorial é resposta longa e específica: 3 já cobrem a pergunta e sobra contexto.
        $relevantes = $pontuar($tutoriais, 3)->concat($pontuar($geral, 5));

        return $team->concat($relevantes)->values();
    }

    /**
     * Ordena os conhecimentos pela pergunta do cliente.
     *
     * Contar palavras casadas não bastava: "lojista", "conta" e "cadastro" aparecem em
     * quase todo tutorial e empatavam tudo — "quero vender maquininha" caía no tutorial de
     * menu. Aqui cada palavra vale o INVERSO de quantos conhecimentos a contêm (palavra que
     * casa com tudo quase não pontua; "favicon", "smtp", "antecipacao" decidem), e casar
     * no gatilho vale o dobro de casar nas palavras-chave. O conteúdo fica de fora de
     * propósito: texto longo casa com qualquer coisa.
     */
    private function pontuarPorRelevancia(Collection $chunks, Collection $words, string $pergunta, int $limite): Collection
    {
        if ($chunks->isEmpty() || $words->isEmpty()) {
            return collect();
        }

        $campos = $chunks->mapWithKeys(fn ($c) => [$c->id => [
            'gatilho' => self::normalizar((string) $c->gatilho),
            'busca' => self::normalizar(($c->keywords ?? '').' '.$c->gatilho),
        ]]);

        $frequencia = $words->mapWithKeys(fn ($w) => [
            $w => $campos->filter(fn ($f) => str_contains($f['busca'], $w))->count(),
        ]);

        return $chunks->map(function ($c) use ($words, $campos, $frequencia, $pergunta) {
            $f = $campos[$c->id];
            $c->score = $words->sum(function ($w) use ($f, $frequencia) {
                if ($frequencia[$w] === 0 || ! str_contains($f['busca'], $w)) {
                    return 0;
                }
                // Teto no peso: com 44 conhecimentos, uma palavra que só aparece em um deles
                // ganharia peso 1 e decidiria sozinha — inclusive quando é palavra à toa.
                $peso = min(1 / $frequencia[$w], 0.5);

                return str_contains($f['gatilho'], $w) ? $peso * 2 : $peso;
            });

            // Frase inteira das palavras-chave dentro da pergunta ("vender maquininha",
            // "trocar a logo") é o sinal mais forte que existe: vale mais que palavra solta.
            $c->score += 1.5 * collect(explode(',', self::normalizar((string) $c->keywords)))
                ->map(fn ($k) => trim($k))
                ->filter(fn ($k) => str_contains($k, ' ') && str_contains($pergunta, $k))
                ->count();

            return $c;
        })->filter(fn ($c) => $c->score > 0)->sortByDesc('score')->take($limite);
    }

    /** Minúsculas e sem acento — o cliente digita "usuario", o cadastro diz "usuário". */
    private static function normalizar(string $texto): string
    {
        return strtr(mb_strtolower($texto), [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
    }
}
