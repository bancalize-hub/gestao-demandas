<?php

namespace App\Services;

use App\Models\ChatTab;
use App\Models\Conversation;
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
    /** Monta o prompt (voz + conhecimento + histórico) e gera a resposta. Retorna null se indisponível. */
    public function generate(Conversation $conversation, ?string $instruction = null, ?string $previous = null): ?string
    {
        if (! config('services.claude.oauth_token')) {
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
            $gUser = User::whereNotNull('google_access_token')->first();
            if ($gUser && $gUser->hasGoogle()) {
                $slots = app(GoogleCalendarService::class)->freeSlots($gUser, 60);
                if ($slots) {
                    $list = collect($slots)->take(8)->map(fn ($s) => '- '.$s['label'])->implode("\n");
                    $agendaBlock = "HORÁRIOS REAIS LIVRES NA AGENDA (são os ÚNICOS disponíveis; reunião dura 1 hora):\n{$list}\n\n";
                    $agendaRule = '- Ao propor reunião, ofereça 2 ou 3 dos HORÁRIOS REAIS LIVRES listados acima, copiando exatamente (dia e hora). NUNCA invente nem ofereça datas/horários fora dessa lista. Cada reunião dura 1 hora.';
                }
            }
        } catch (\Throwable $e) {
            // sem agenda disponível → mantém a regra de perguntar a preferência
        }

        $task = ($instruction && $previous)
            ? "Você ia mandar esta mensagem:\n\"{$previous}\"\n\nReescreva-a aplicando este ajuste pedido pelo atendente: \"{$instruction}\". Mantenha o estilo, as regras, o conhecimento e o objetivo da etapa."
            : 'Escreva a próxima mensagem do Atendente.';

        $prompt = <<<TXT
        Você é o ATENDENTE escrevendo a próxima mensagem para um lead no WhatsApp.
        Lead: {$conversation->name}. Etapa do funil: {$stageName}.

        {$agora}{$objetivo}{$context}{$agendaBlock}
        Conversa (Atendente = você; {$conversation->name} = lead):
        {$transcript}

        {$task}
        Regras de saída:
        - Use EXATAMENTE o estilo/voz e as regras descritas acima (se houver).
        - Use o conhecimento acima quando fizer sentido; nunca invente preços/políticas.
        - Respeite SEU PAPEL e persiga o OBJETIVO (se houver) de forma sutil, no ritmo da conversa.
        {$agendaRule}
        - NUNCA diga que enviou o convite, que marcou/agendou a reunião nem que "está confirmado/agendado":
          a confirmação real (com o link do Meet) é enviada automaticamente pelo sistema, não por você.
        - Só cumprimente ("{$saudacao}") no início da conversa ou após uma longa pausa; ao saudar, respeite o horário atual indicado acima.
        - Português do Brasil, no máximo 2-3 frases curtas.
        - Sem aspas, sem rótulos — só o texto da mensagem.
        TXT;

        $out = Claude::run($prompt, 60);

        return $out !== null ? trim($out) : null;
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

        $words = collect(preg_split('/\W+/u', mb_strtolower($recent)))
            ->filter(fn ($w) => mb_strlen($w) >= 4)->unique();

        // O playbook do TIME (como o SDR/Closer/CS atende) entra SEMPRE: é sobre o nosso
        // comportamento, não sobre o que o lead escreveu — depender de palavra-chave fazia
        // ele quase nunca aparecer. O conhecimento geral (produto, preço, objeções) segue
        // por relevância, que é o que evita despejar 60 chunks no prompt.
        $teamId = ChatTab::forStage($conversation->stage)?->id;
        $team = $teamId ? MemoryChunk::where('chat_tab_id', $teamId)->latest('id')->take(8)->get() : collect();

        $geral = MemoryChunk::whereNull('chat_tab_id')->get();
        if ($words->isEmpty() || $geral->isEmpty()) {
            return $team->concat($geral->take(5))->values();
        }

        $relevantes = $geral->map(function ($c) use ($words) {
            $hay = mb_strtolower(($c->keywords ?? '').' '.$c->gatilho);
            $c->score = $words->filter(fn ($w) => str_contains($hay, $w))->count();

            return $c;
        })->filter(fn ($c) => $c->score > 0)->sortByDesc('score')->take(5);

        return $team->concat($relevantes)->values();
    }
}
