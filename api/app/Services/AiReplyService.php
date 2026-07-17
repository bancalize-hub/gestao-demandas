<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\MemoryChunk;
use App\Models\Stage;
use App\Models\StyleProfile;
use App\Models\StyleRule;
use App\Models\StyleSample;
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

        $transcript = $conversation->messages()
            ->where(function ($q) {
                $q->where(fn ($t) => $t->where('type', 'text')->whereNotNull('text'))
                    ->orWhere(fn ($v) => $v->where('type', 'voice')->whereNotNull('transcript')->where('transcript', '!=', ''));
            })
            ->reorder()->orderByRaw('ts IS NULL, ts')->orderBy('id')
            ->get(['is_out', 'type', 'text', 'transcript'])
            ->map(function ($m) use ($conversation) {
                $who = $m->is_out ? 'Atendente' : $conversation->name;
                $content = $m->type === 'voice' ? '[áudio do cliente] '.$m->transcript : $m->text;

                return $who.': '.$content;
            })
            ->implode("\n");

        if ($transcript === '') {
            $transcript = '(sem mensagens ainda — o lead acabou de iniciar a conversa)';
        }

        $context = $this->memoryContext($conversation);

        // Objetivo definido para a etapa atual do funil (guia sutil da conversa).
        $stage = Stage::where('key', $conversation->stage)->first();
        $stageName = $stage->name ?? $conversation->stage;
        $objetivo = '';
        if ($stage && trim((string) $stage->goal) !== '') {
            $objetivo = "OBJETIVO NESTA ETAPA DO FUNIL (\"{$stageName}\"):\n{$stage->goal}\n"
                ."Conduza a conversa de forma sutil e natural rumo a esse objetivo — sem ser insistente, "
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
            $gUser = \App\Models\User::whereNotNull('google_access_token')->first();
            if ($gUser && $gUser->hasGoogle()) {
                $slots = app(GoogleCalendarService::class)->freeSlots($gUser, 60);
                if ($slots) {
                    $list = collect($slots)->take(8)->map(fn ($s) => '- '.$s['label'])->implode("\n");
                    $agendaBlock = "HORÁRIOS REAIS LIVRES NA AGENDA (são os ÚNICOS disponíveis; reunião dura 1 hora):\n{$list}\n\n";
                    $agendaRule = '- Ao propor reunião, ofereça 2 ou 3 dos HORÁRIOS REAIS LIVRES listados acima, copiando exatamente (dia e hora). NUNCA invente nem ofereça datas/horários fora dessa lista. Cada reunião dura 1 hora.';
                }
            }
        }
        catch (\Throwable $e) {
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
        - Persiga o OBJETIVO DA ETAPA (se houver) de forma sutil, no ritmo da conversa.
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

    /** Recupera os chunks mais relevantes por palavra-chave nas últimas mensagens do lead. */
    private function relevantChunks(Conversation $conversation): Collection
    {
        $recent = $conversation->messages()
            ->where('type', 'text')->where('is_out', false)->whereNotNull('text')
            ->reorder()->orderByDesc('ts')->orderByDesc('id')->take(3)->pluck('text')->implode(' ');

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
