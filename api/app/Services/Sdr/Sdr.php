<?php

namespace App\Services\Sdr;

use App\Console\Commands\MeetingReminderTick;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\LeadActivity;
use App\Models\MarketingAutopilot;
use App\Models\MarketingGestorSession;
use App\Models\Meeting;
use App\Models\Message;
use App\Models\SdrAgent;
use App\Models\Stage;
use App\Services\AiReplyService;
use App\Services\ChatSender;
use App\Services\Marketing\Avisos;
use App\Support\Attendance;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A caixa de ferramentas do agente SDR — e as travas que ele não passa.
 *
 * O agente decide QUEM procurar, QUANDO e O QUE dizer. O que mora aqui, em código, é o que a
 * conta não aguenta errar: em 14/08/2026 a Meta travou o número por "spam" depois de a
 * retomada automática mandar 2+ mensagens a ~100 conversas/dia que já não respondiam. Toda
 * mensagem do SDR passa por {@see bloqueio()} — sequência sem resposta, intervalo, teto do
 * dia, horário, janela de 24h, humano no comando, lead reprovado.
 *
 * Mensagem do SDR é carimbada com `messages.meta = 'sdr'`: é por ela que as travas contam.
 */
class Sdr
{
    /** Lead parado há mais que isso não é retomada, é campanha (com anti-ban próprio). */
    public const DIAS_FILA = 30;

    public function __construct(
        private SdrAgent $cfg,
        private bool $simular = false,
        private ?int $sessao = null,
    ) {}

    // ───────────────────────── leitura ─────────────────────────

    /**
     * Quem está no caminho até a call: a IA atende, a triagem não reprovou, ninguém ainda
     * compareceu a uma reunião (daí em diante é o closer) e a conversa não está no fim do funil.
     */
    public function escopo()
    {
        $final = $this->etapaFinal();

        return Conversation::query()
            ->where('auto_reply', true)
            ->where('archived', false)
            ->whereNotNull('phone')
            ->where(fn ($q) => $q->whereNull('qualified')->orWhere('qualified', true))
            ->where('last_message_at', '>=', now()->subDays(self::DIAS_FILA))
            ->when($final, fn ($q) => $q->where(fn ($s) => $s->whereNull('stage')->orWhere('stage', '!=', $final)))
            ->whereDoesntHave('meetings', fn ($m) => $m->where('attended', true));
    }

    /** A fila, do mais urgente para o menos. */
    public function fila(bool $todos = false): string
    {
        $linhas = $this->escopo()->orderByDesc('last_message_at')->limit(150)->get()
            ->map(fn (Conversation $c) => $this->situacao($c))
            ->filter(fn ($s) => $s['entrada'] !== null)   // nunca respondeu = prospecção fria
            ->sortBy([['prioridade', 'asc'], ['ultima_ts', 'desc']])
            ->values();

        if ($linhas->isEmpty()) {
            return 'Fila vazia: nenhum lead no caminho até a call.';
        }

        $mostrar = $todos ? $linhas : $linhas->take(40);
        $hoje = $this->enviadasHoje();
        $cab = sprintf('%d leads no caminho até a call. Mensagens do SDR hoje: %d de %d. Horário de atendimento: %s.',
            $linhas->count(), $hoje, $this->cfg->daily_cap, $this->dentroDoHorario() ? 'ABERTO' : 'FECHADO (nada sai agora)');

        $corpo = $mostrar->map(function ($s) {
            $c = $s['conv'];

            return "#{$c->id} {$c->name} — [{$s['categoria']}] etapa {$s['etapa']}"
                ."\n   última: {$s['ultima']} · sem resposta: {$s['seguidas_sdr']} do SDR / {$s['seguidas']} nossas"
                .' · janela: '.($s['janela'] ? 'aberta' : 'FECHADA (só template)')
                .($s['reuniao'] ? " · reunião: {$s['reuniao']}" : '')
                .($c->sdr_follow_at ? ' · você marcou voltar '.$c->sdr_follow_at->format('d/m H:i').($c->sdr_follow_note ? " ({$c->sdr_follow_note})" : '') : '')
                ."\n   ".($s['bloqueio'] ? "não pode enviar: {$s['bloqueio']}" : 'pode enviar');
        })->implode("\n");

        return $cab."\n\n".$corpo.($linhas->count() > $mostrar->count() ? "\n\n(+".($linhas->count() - $mostrar->count()).' — `sdr fila --todos`)' : '');
    }

    /** Tudo que se precisa para decidir sobre um lead. */
    public function ficha(int $id): string
    {
        $c = $this->conv($id);
        $s = $this->situacao($c);

        $reunioes = $c->meetings()->orderByDesc('starts_at')->limit(5)->get()->map(fn (Meeting $m) => '- '
            .$m->starts_at->format('d/m H:i').' — '.$this->estadoReuniao($m)
            .($m->meet_link ? " ({$m->meet_link})" : ''))->implode("\n") ?: '(nenhuma)';

        $atividades = LeadActivity::where('conversation_id', $c->id)->orderByDesc('occurred_at')->limit(12)->get()
            ->map(fn ($a) => '- '.$a->occurred_at?->format('d/m H:i')." [{$a->type}] {$a->title}".($a->body && str_starts_with($a->type, 'sdr') ? ': '.Str::limit($a->body, 200) : ''))
            ->implode("\n") ?: '(nada)';

        $msgs = $c->messages()->reorder()->orderByDesc('ts')->orderByDesc('id')->limit(40)->get()->reverse()
            ->map(function (Message $m) use ($c) {
                $quem = $m->is_out ? ($m->meta === 'sdr' ? 'Nós [SDR]' : 'Nós') : ($c->name ?: 'Lead');
                $txt = match ($m->type) {
                    'voice' => '[áudio] '.($m->transcript ?: '(sem transcrição)'),
                    'image' => '[imagem] '.($m->transcript ?: $m->text ?: ''),
                    'file' => '[arquivo] '.($m->file_name ?: ''),
                    default => (string) $m->text,
                };
                $erro = $m->is_out && $m->status === 'error' ? ' ⚠ NÃO ENTREGUE' : '';

                return ($m->ts ? date('d/m H:i', (int) $m->ts) : '--/-- --:--')." {$quem}: ".Str::limit(trim($txt), 600).$erro;
            })->implode("\n");

        return "#{$c->id} {$c->name} · {$c->phone}\n"
            ."Etapa: {$s['etapa']} · categoria: {$s['categoria']}\n"
            .'Triagem: '.($c->qualified === true ? 'QUALIFICADO' : 'ainda não julgado').($c->qualified_reason ? " — {$c->qualified_reason}" : '')."\n"
            .($c->company ? "Empresa do lead: {$c->company}\n" : '')
            .($c->notes ? 'Observações do time: '.Str::limit($c->notes, 800)."\n" : '')
            .'Janela de 24h: '.($s['janela'] ? 'aberta (texto livre)' : 'FECHADA — só `sdr template`')."\n"
            .'Sequência sem resposta: '.$s['seguidas_sdr'].' do SDR (máx '.$this->cfg->max_unanswered.')'."\n"
            .($c->sdr_follow_at ? 'Você marcou voltar: '.$c->sdr_follow_at->format('d/m H:i').($c->sdr_follow_note ? " — {$c->sdr_follow_note}" : '')."\n" : '')
            .'Pode enviar agora: '.($s['bloqueio'] ? "NÃO — {$s['bloqueio']}" : 'sim')."\n\n"
            ."Reuniões:\n{$reunioes}\n\nLinha do tempo:\n{$atividades}\n\nÚltimas mensagens:\n".($msgs ?: '(nenhuma)');
    }

    /**
     * Rascunho escrito pelo atendimento da empresa (base de conhecimento, objetivo do time,
     * horários REAIS da agenda, materiais). Não envia nada.
     */
    public function rascunhar(int $id, string $instrucao): string
    {
        $c = $this->conv($id);
        if (trim($instrucao) === '') {
            return 'Falta --instrucao="o que a mensagem tem que fazer".';
        }
        $texto = app(AiReplyService::class)->generate($c, $instrucao);
        if ($texto === null || trim($texto) === '') {
            return 'A IA de atendimento não gerou o rascunho (fora do ar?). Escreva você mesmo ou tente no próximo turno.';
        }
        if (AiReplyService::pareceRecusa($texto)) {
            return "A IA de atendimento se recusou a escrever (normalmente porque o lead pediu para parar):\n{$texto}";
        }

        return "RASCUNHO (não enviado — revise e use `sdr enviar {$id} --texto=\"…\"`):\n{$texto}";
    }

    /** O que o SDR fez nas últimas 72h e o que veio depois. */
    public function diario(): string
    {
        $enviadas = Message::where('meta', 'sdr')->where('is_out', true)->where('created_at', '>=', now()->subHours(72))
            ->orderByDesc('id')->limit(60)->get();

        $linhas = $enviadas->map(function (Message $m) {
            $c = Conversation::find($m->conversation_id);
            $resp = $c ? $c->messages()->reorder()->where('is_out', false)->where('ts', '>', (int) $m->ts)->count() : 0;
            $reu = $c ? Meeting::where('conversation_id', $c->id)->whereNull('cancelled_at')->where('created_at', '>', $m->created_at)->exists() : false;

            return '- '.$m->created_at->format('d/m H:i')." #{$m->conversation_id} ".($c?->name ?? '?').': "'.Str::limit((string) $m->text, 140).'"'
                .' → '.($resp ? "respondeu ({$resp} msg)" : 'sem resposta').($reu ? ' · MARCOU reunião' : '');
        })->implode("\n");

        $de = now()->subDays(14);
        $marcadas = Meeting::where('created_at', '>=', $de)->count();
        $passadas = Meeting::whereNull('cancelled_at')->whereBetween('starts_at', [$de, now()])->count();
        $foram = Meeting::whereNull('cancelled_at')->whereBetween('starts_at', [$de, now()])->where('attended', true)->count();

        return "Últimos 14 dias: {$marcadas} reuniões marcadas; das {$passadas} que já aconteceram, {$foram} com o lead presente.\n\n"
            ."Mensagens do SDR nas últimas 72h:\n".($linhas ?: '(nenhuma)');
    }

    // ───────────────────────── ações ─────────────────────────

    public function enviar(int $id, string $texto, string $motivo): string
    {
        $c = $this->conv($id);
        [$texto, $material] = AiReplyService::extrairMaterial(trim($texto));
        $texto = trim($texto);
        if ($texto === '') {
            return 'RECUSADO: mensagem vazia.';
        }
        if ($b = $this->bloqueio($c)) {
            return "RECUSADO: {$b}";
        }
        if (! $c->canSendFreeform()) {
            return 'RECUSADO: janela de 24h fechada no número oficial — texto livre não sai. Use `sdr template`.';
        }
        if ($this->simular) {
            return "SIMULAÇÃO — enviaria para #{$c->id}: {$texto}".($material ? " (+ material {$material->filename})" : '');
        }

        $msg = app(ChatSender::class)->text($c, $texto);
        if (! $msg) {
            return 'FALHOU: o WhatsApp recusou o envio. Não tente de novo neste turno.';
        }
        $this->registrar($c, $msg, $motivo, $texto);
        if ($material) {
            app(ChatSender::class)->material($c, $material);
        }

        return "ENVIADO para #{$c->id}.";
    }

    /**
     * Fora da janela de 24h no número oficial: só template aprovado. `retomada` reabre a
     * conversa ("ficou uma pendência sobre ___"); `reuniao` é o lembrete com data e link.
     */
    public function template(int $id, string $tipo, string $assunto, string $motivo): string
    {
        $c = $this->conv($id);
        if ($b = $this->bloqueio($c)) {
            return "RECUSADO: {$b}";
        }
        if ($c->canSendFreeform()) {
            return 'A janela está ABERTA: use `sdr enviar` (texto livre conversa melhor que template).';
        }

        if ($tipo === 'reuniao') {
            $m = Meeting::activeFor($c->id);
            if (! $m) {
                return 'RECUSADO: não há reunião futura marcada para lembrar.';
            }
            $p = MeetingReminderTick::messageParts($m);
            $nome = (string) config('services.meeting_reminder.template');
            $vars = [$p['nome'], $p['quando'], $p['link']];
            $espelho = MeetingReminderTick::buildMessage($p);
        } elseif ($tipo === 'retomada') {
            $assunto = trim(preg_replace('/\s+/u', ' ', $assunto) ?? '', " \t\"'.");
            if ($assunto === '' || mb_strlen($assunto) > 60) {
                return 'Falta --assunto="…" (até 60 caracteres, encaixa em "ficou uma pendência da nossa parte sobre ___").';
            }
            $nome = (string) config('services.sdr.template_retomada');
            $vars = [$this->primeiroNome($c), $assunto];
            $espelho = str_replace([':nome', ':assunto'], $vars, (string) config('services.sdr.template_retomada_text'));
        } else {
            return 'Tipo inválido: use --tipo=retomada ou --tipo=reuniao.';
        }

        if ($nome === '') {
            return 'RECUSADO: template não configurado.';
        }
        if ($this->simular) {
            return "SIMULAÇÃO — enviaria o template {$nome} para #{$c->id}: {$espelho}";
        }

        $msg = app(ChatSender::class)->template($c, $nome, (string) config('services.meeting_reminder.template_language', 'pt_BR'), $vars, $espelho);
        if (! $msg) {
            return 'FALHOU: a Meta recusou o template. Não tente de novo neste turno.';
        }
        $this->registrar($c, $msg, $motivo, $espelho);

        return "ENVIADO (template {$nome}) para #{$c->id}.";
    }

    public function lembrar(int $id, float $horas, string $nota): string
    {
        $c = $this->conv($id);
        $horas = max(1, min(240, $horas));
        if (! $this->simular) {
            $c->update(['sdr_follow_at' => now()->addMinutes((int) round($horas * 60)), 'sdr_follow_note' => Str::limit($nota, 480, '')]);
        }

        return "Marcado: volto em #{$c->id} ".now()->addMinutes((int) round($horas * 60))->format('d/m H:i').'.';
    }

    public function pausar(int $id, int $dias, string $motivo): string
    {
        $c = $this->conv($id);
        $dias = max(1, min(60, $dias));
        if (! $this->simular) {
            $c->update(['sdr_paused_until' => now()->addDays($dias), 'sdr_follow_at' => null, 'sdr_follow_note' => null]);
            LeadActivity::log($c->id, 'sdr_nota', "SDR deixou o lead quieto por {$dias} dia(s)", $motivo);
        }

        return "#{$c->id} fora da sua fila por {$dias} dia(s).";
    }

    public function anotar(int $id, string $texto): string
    {
        $c = $this->conv($id);
        if (trim($texto) === '') {
            return 'Falta --texto.';
        }
        if (! $this->simular) {
            LeadActivity::log($c->id, 'sdr_nota', 'Nota do SDR', Str::limit($texto, 2000));
        }

        return "Anotado na ficha de #{$c->id}.";
    }

    /** Fala com o dono no WhatsApp (o mesmo número de aviso do gestor de tráfego). */
    public function avisar(string $texto, ?int $id = null): string
    {
        if (trim($texto) === '') {
            return 'Falta --texto.';
        }
        $c = $id ? $this->conv($id) : null;
        $corpo = ($c ? "Lead #{$c->id} {$c->name} ({$c->phone})\n" : '').$texto;
        $ok = (new Avisos(MarketingAutopilot::atual(), $this->simular, 'SDR'))->enviar('sdr-'.md5($corpo), $corpo, 12);

        return $ok ? 'Aviso enviado ao dono.' : 'Aviso NÃO enviado (repetido nas últimas 12h ou sem número de aviso configurado).';
    }

    public function aprender(string $texto): string
    {
        if (trim($texto) === '') {
            return 'Falta --texto.';
        }
        $l = $this->cfg->learnings ?? [];
        $l[] = ['quando' => now()->format('d/m/Y'), 'texto' => Str::limit(trim($texto), 400, '')];
        if (! $this->simular) {
            $this->cfg->update(['learnings' => array_slice($l, -40)]);
        }

        return 'Aprendizado guardado (entra no seu contexto dos próximos turnos).';
    }

    public function esquecer(int $n): string
    {
        $l = $this->cfg->learnings ?? [];
        if (! isset($l[$n - 1])) {
            return "Não existe o aprendizado {$n}.";
        }
        array_splice($l, $n - 1, 1);
        if (! $this->simular) {
            $this->cfg->update(['learnings' => $l]);
        }

        return "Aprendizado {$n} removido.";
    }

    public function voltarEm(int $minutos, string $motivo): string
    {
        $minutos = max(SdrAgente::MIN_INTERVALO, min(SdrAgente::MAX_INTERVALO, $minutos));
        if (! $this->simular) {
            $this->cfg->update(['next_review_at' => now()->addMinutes($minutos)]);
        }

        return 'Próximo turno: '.now()->addMinutes($minutos)->format('d/m H:i')." ({$motivo}).";
    }

    // ───────────────────────── travas ─────────────────────────

    /**
     * Por que o SDR NÃO pode mandar mensagem a este lead agora (null = pode). É a parte que o
     * agente não negocia — a mesma resposta vale para texto livre e template.
     */
    public function bloqueio(Conversation $c): ?string
    {
        if (! $this->simular && ! $this->cfg->enabled) {
            return 'o SDR está desligado no painel';
        }
        if (! $c->auto_reply) {
            return 'atendimento automático desligado nesta conversa (ou o lead pediu para parar)';
        }
        if ($c->archived || ! $c->phone) {
            return 'conversa arquivada ou sem telefone';
        }
        if ($c->qualified === false) {
            return 'lead reprovado na triagem';
        }
        if (Meeting::where('conversation_id', $c->id)->where('attended', true)->exists()) {
            return 'o lead já compareceu a uma reunião — agora é com o closer';
        }
        if ($c->ai_paused_until && $c->ai_paused_until->isFuture()) {
            return 'um humano está conduzindo a conversa (IA pausada até '.$c->ai_paused_until->format('d/m H:i').')';
        }
        if ($c->sdr_paused_until && $c->sdr_paused_until->isFuture()) {
            return 'você mesmo pausou este lead até '.$c->sdr_paused_until->format('d/m');
        }
        if ($c->auto_reply_due_at) {
            return 'o lead escreveu e o atendimento automático vai responder em instantes';
        }
        if (! $this->dentroDoHorario()) {
            return 'fora do horário de atendimento';
        }

        $entrada = $c->lastInboundTs();
        if (! $entrada) {
            return 'o lead nunca respondeu — isso é prospecção, não SDR';
        }
        $seguidas = $this->seguidasSdr($c, $entrada);
        if ($seguidas >= $this->cfg->max_unanswered) {
            return "já são {$seguidas} mensagens do SDR sem resposta (máximo {$this->cfg->max_unanswered}) — espere ele falar";
        }
        $ultimaSdr = $this->ultimaSdrTs($c);
        if ($ultimaSdr && $ultimaSdr > $entrada && now()->timestamp - $ultimaSdr < $this->cfg->min_hours_between * 3600) {
            return 'sua última mensagem foi há '.$this->humano(now()->timestamp - $ultimaSdr)." e ele não respondeu (mínimo {$this->cfg->min_hours_between}h)";
        }
        if ($this->enviadasHoje() >= $this->cfg->daily_cap) {
            return "teto do dia atingido ({$this->cfg->daily_cap} mensagens do SDR)";
        }
        if ($this->enviadasNoTurno() >= $this->cfg->per_turn_cap) {
            return "teto do turno atingido ({$this->cfg->per_turn_cap}) — o resto fica para o próximo";
        }

        return null;
    }

    public function dentroDoHorario(): bool
    {
        return Attendance::dentro(Company::find($this->cfg->company_id));
    }

    public function enviadasHoje(): int
    {
        return Message::where('meta', 'sdr')->where('is_out', true)->where('created_at', '>=', now()->startOfDay())->count();
    }

    private function enviadasNoTurno(): int
    {
        $inicio = $this->sessao ? MarketingGestorSession::find($this->sessao)?->started_at : null;

        return $inicio ? Message::where('meta', 'sdr')->where('is_out', true)->where('created_at', '>=', $inicio)->count() : 0;
    }

    private function seguidasSdr(Conversation $c, int $entrada): int
    {
        return $c->messages()->reorder()->where('is_out', true)->where('meta', 'sdr')->where('ts', '>', $entrada)->count();
    }

    private function ultimaSdrTs(Conversation $c): ?int
    {
        $ts = $c->messages()->reorder()->where('is_out', true)->where('meta', 'sdr')->max('ts');

        return $ts ? (int) $ts : null;
    }

    // ───────────────────────── vigia (sem IA) ─────────────────────────

    /**
     * O que não pode esperar o próximo turno marcado: lead que faltou à reunião (remarcar
     * enquanto ainda está quente) e lembrete que o próprio agente deixou para agora.
     */
    public function urgente(): ?string
    {
        $desde = $this->cfg->last_review_at ?? now()->subDay();

        $faltas = Meeting::whereNull('cancelled_at')->where('attended', false)
            ->whereNotNull('attendance_checked_at')->where('attendance_checked_at', '>', $desde)
            ->where('starts_at', '>=', now()->subDays(2))->where('starts_at', '<=', now())
            ->whereIn('conversation_id', $this->escopo()->select('id'))
            ->whereNotIn('conversation_id', Meeting::active()->select('conversation_id'))
            ->count();
        if ($faltas) {
            return "{$faltas} lead(s) faltaram à reunião desde o seu último turno — remarque enquanto está quente";
        }

        $vencidos = (clone $this->escopo())->whereNotNull('sdr_follow_at')->where('sdr_follow_at', '<=', now())->count();

        return $vencidos ? "{$vencidos} lead(s) com o retorno que você marcou vencido" : null;
    }

    // ───────────────────────── miúdos ─────────────────────────

    /** @return array<string, mixed> */
    private function situacao(Conversation $c): array
    {
        $entrada = $c->lastInboundTs();
        $ultima = $c->messages()->reorder()->orderByDesc('ts')->orderByDesc('id')->first(['is_out', 'ts']);
        $proxima = Meeting::activeFor($c->id);
        $passada = $proxima ? null : $c->meetings()->whereNull('cancelled_at')->where('starts_at', '<=', now())->orderByDesc('starts_at')->first();
        $faltou = $passada && ! $passada->attended && $passada->attendance_checked_at && $passada->starts_at->gte(now()->subDays(10));

        [$categoria, $prioridade] = match (true) {
            $faltou => ['FALTOU à reunião de '.$passada->starts_at->format('d/m H:i'), 0],
            $proxima && $proxima->starts_at->lte(now()->addHours(48)) => ['reunião em breve', 1],
            $c->sdr_follow_at && $c->sdr_follow_at->isPast() => ['retorno que você marcou', 2],
            // Só recente: lead que falou há semanas e ficou sem resposta não é urgência, é retomada.
            $ultima && ! $ultima->is_out && (int) $ultima->ts >= now()->subDays(3)->timestamp => ['lead falou por último', 3],
            (bool) $proxima => ['reunião marcada', 4],
            $c->qualified === true => ['QUALIFICADO sem reunião', 5],
            default => ['em conversa (triagem)', 6],
        };

        return [
            'conv' => $c,
            'entrada' => $entrada,
            'categoria' => $categoria,
            'prioridade' => $prioridade,
            'etapa' => Stage::where('key', $c->stage)->value('name') ?? ($c->stage ?: '—'),
            'ultima_ts' => (int) ($ultima->ts ?? 0),
            'ultima' => $ultima ? ($ultima->is_out ? 'nossa' : 'DELE').' há '.$this->humano(now()->timestamp - (int) $ultima->ts) : '—',
            'seguidas' => $entrada ? $c->messages()->reorder()->where('is_out', true)->where('ts', '>', $entrada)->count() : 0,
            'seguidas_sdr' => $entrada ? $this->seguidasSdr($c, $entrada) : 0,
            'janela' => $c->canSendFreeform(),
            'reuniao' => $proxima ? $proxima->starts_at->format('d/m H:i').($proxima->reminder_sent_at ? ' (lembrete de 1h já foi)' : '') : null,
            'bloqueio' => $this->bloqueio($c),
        ];
    }

    private function estadoReuniao(Meeting $m): string
    {
        return match (true) {
            (bool) $m->cancelled_at => 'cancelada'.($m->cancel_reason ? " ({$m->cancel_reason})" : ''),
            $m->starts_at->isFuture() => 'marcada',
            (bool) $m->attended => 'COMPARECEU',
            (bool) $m->attendance_checked_at => 'FALTOU',
            default => 'aconteceu, presença ainda não apurada',
        };
    }

    private function registrar(Conversation $c, Message $msg, string $motivo, string $texto): void
    {
        $msg->update(['meta' => 'sdr']);
        $c->update(['sdr_follow_at' => null, 'sdr_follow_note' => null]);
        LeadActivity::log($c->id, 'sdr', 'SDR: '.Str::limit($motivo ?: 'mensagem', 180), $texto);
    }

    private function conv(int $id): Conversation
    {
        $c = Conversation::find($id);
        if (! $c) {
            throw new \RuntimeException("Conversa #{$id} não existe nesta empresa.");
        }

        return $c;
    }

    private function etapaFinal(): ?string
    {
        return Stage::orderByDesc('position')->orderByDesc('id')->value('key');
    }

    private function primeiroNome(Conversation $c): string
    {
        $p = preg_split('/\s+/', trim((string) $c->name))[0] ?? '';

        return ($p !== '' && ! preg_match('/^\+?\d+$/', $p)) ? $p : 'tudo bem';
    }

    private function humano(int $s): string
    {
        return match (true) {
            $s < 5400 => max(1, (int) round($s / 60)).' min',
            $s < 172800 => (int) round($s / 3600).'h',
            default => (int) round($s / 86400).' dias',
        };
    }

    /** Para a página: a fila resumida, sem texto. */
    public function filaResumo(): Collection
    {
        return $this->escopo()->orderByDesc('last_message_at')->limit(150)->get()
            ->map(fn (Conversation $c) => $this->situacao($c))
            ->filter(fn ($s) => $s['entrada'] !== null)
            ->sortBy([['prioridade', 'asc'], ['ultima_ts', 'desc']])
            ->values()
            ->map(fn ($s) => ['id' => $s['conv']->id, 'slug' => $s['conv']->slug, 'nome' => $s['conv']->name, 'categoria' => $s['categoria'], 'etapa' => $s['etapa'],
                'ultima' => $s['ultima'], 'reuniao' => $s['reuniao'], 'bloqueio' => $s['bloqueio'], 'seguidas_sdr' => $s['seguidas_sdr']]);
    }
}
