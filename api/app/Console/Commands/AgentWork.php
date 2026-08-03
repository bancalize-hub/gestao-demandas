<?php

namespace App\Console\Commands;

use App\Models\AgentJob;
use App\Models\AgentSession;
use App\Support\Claude;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Worker do agente operacional. Roda continuamente (pm2) e, para cada job pendente,
 * executa o Claude Code COMO o usuário gestao-agent (não-root, --dangerously-skip-permissions),
 * fazendo streaming da saída para a tabela (a página lê por polling). Obedece o botão de matar.
 */
class AgentWork extends Command
{
    protected $signature = 'agent:work';

    protected $description = 'Executa os jobs do agente operacional via Claude Code (usuário gestao-agent)';

    private const RUN_AS = 'gestao-agent';

    /** O agente de marketing roda como www-data: seu servidor MCP é um artisan. */
    private const RUN_AS_MARKETING = 'www-data';

    /** HOME e cwd do agente de marketing (área vazia, fora do código-fonte). */
    private const HOME_WWW = '/var/www/gestao/api/storage/app/claude-home';

    private const WORKSPACE = '/var/www/gestao/api/storage/app/marketing-agent';

    private const CLAUDE = '/usr/local/bin/claude';

    /** Espelho do token do painel, legível só pelo gestao-agent (ver sincronizaCredencial). */
    private const TOKEN_FILE = '/home/'.self::RUN_AS.'/.claude-token';

    /**
     * Ferramentas nativas bloqueadas no agente de marketing.
     *
     * NÃO simplifique isto para só `--tools ""`: testado em 03/08/2026, `--tools ""`
     * sozinho ainda deixou Monitor, PushNotification e RemoteTrigger de pé — e o
     * Monitor executa comando de shell (o agente rodou `grep` no servidor no teste).
     * A lista explícita é o que zera de fato (`tools: []` no evento de init).
     * Ao atualizar o CLI, conferir se surgiu ferramenta nova: rode um `claude -p`
     * sem restrição e compare o array `tools` do evento system/init com esta lista.
     */
    private const NATIVAS_BLOQUEADAS = [
        'Task', 'AskUserQuestion', 'Bash', 'CronCreate', 'CronDelete', 'CronList', 'DesignSync',
        'Edit', 'EnterPlanMode', 'EnterWorktree', 'ExitPlanMode', 'ExitWorktree', 'Monitor',
        'NotebookEdit', 'PushNotification', 'Read', 'RemoteTrigger', 'ScheduleWakeup', 'Skill',
        'TaskCreate', 'TaskGet', 'TaskList', 'TaskOutput', 'TaskStop', 'TaskUpdate', 'ToolSearch',
        'WebFetch', 'WebSearch', 'Workflow', 'Write',
    ];

    public function handle(): int
    {
        $this->info('agent:work iniciado — aguardando jobs...');

        while (true) {
            $job = AgentJob::where('status', 'pending')->orderBy('id')->first();
            if (! $job) {
                usleep(700000); // 0.7s

                continue;
            }
            try {
                $this->runJob($job);
            } catch (\Throwable $e) {
                Log::error('agent:work falhou', ['job' => $job->id, 'e' => $e->getMessage()]);
                $job->update(['status' => 'error', 'error' => $e->getMessage(), 'finished_at' => now()]);
            }
        }
    }

    /**
     * O sudo limpa o ambiente, então o CLI do agente enxergaria só a credencial do próprio
     * gestao-agent — que foi revogada em 30/07 e deixou a tela devolvendo um 401 mudo.
     * Aqui o token do painel (o mesmo do resto da IA, trocável em Admin → Memória, sem SSH)
     * é espelhado num arquivo que só o gestao-agent lê, e o comando o exporta na hora.
     */
    private function sincronizaCredencial(string $usuario, string $arquivo): bool
    {
        $token = Claude::token();
        if (! $token) {
            return false;
        }

        if (! is_file($arquivo) || trim((string) @file_get_contents($arquivo)) !== $token) {
            // Fecha as permissões ANTES de escrever: um arquivo 0644 com o token, mesmo
            // por um instante, é o tipo de janela que não precisa existir.
            touch($arquivo);
            @chmod($arquivo, 0600);
            file_put_contents($arquivo, $token."\n");
            @chown($arquivo, $usuario);
            @chgrp($arquivo, $usuario);
        }

        return true;
    }

    /**
     * Agente de MARKETING: mesma tela e mesmo streaming, superfície completamente
     * diferente. `--tools ""` desliga TODAS as ferramentas nativas (sem bash, sem ler
     * ou escrever arquivo, sem web) e o `--strict-mcp-config` faz o CLI ignorar
     * qualquer MCP configurado em outro lugar. Sobra exatamente o servidor fbads —
     * cujas ferramentas criam tudo PAUSADO. Sem `--dangerously-skip-permissions`:
     * o allowlist abaixo é a única autorização que existe.
     *
     * Roda como www-data (não gestao-agent) porque o servidor MCP é um `artisan` e
     * precisa do .env e do storage do Laravel — que são de www-data.
     */
    /**
     * Registra o servidor MCP no config do www-data.
     *
     * NÃO dá para usar `--mcp-config` aqui: testado em 03/08/2026, no modo -p o CLI
     * lê a config inline mas NUNCA sobe o processo do servidor stdio (fica em
     * `status: pending` para sempre e o agente roda com zero ferramentas, alucinando
     * chamadas em texto). Só o servidor registrado no `.claude.json` é iniciado.
     * Por isso a entrada é escrita direto no arquivo — idempotente, sem subprocesso.
     */
    private function registraMcp(AgentSession $session): void
    {
        $entrada = [
            'type' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [base_path('artisan'), 'fbads:mcp', '--company='.(int) $session->company_id],
            'env' => new \stdClass,
        ];

        $arquivo = self::HOME_WWW.'/.claude.json';
        $cfg = is_file($arquivo) ? json_decode((string) @file_get_contents($arquivo), true) : [];
        if (! is_array($cfg)) {
            $cfg = [];
        }

        $atual = $cfg['mcpServers']['fbads'] ?? null;
        $igual = is_array($atual)
            && ($atual['command'] ?? null) === $entrada['command']
            && ($atual['args'] ?? null) === $entrada['args'];
        if ($igual) {
            return;
        }

        $cfg['mcpServers']['fbads'] = $entrada;
        // O CLI também exige o diretório marcado como confiável, senão nem tenta subir.
        $cfg['projects'][self::WORKSPACE]['hasTrustDialogAccepted'] = true;

        if (! is_dir(self::WORKSPACE)) {
            @mkdir(self::WORKSPACE, 0755, true);
            @chown(self::WORKSPACE, self::RUN_AS_MARKETING);
        }
        file_put_contents($arquivo, json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chown($arquivo, self::RUN_AS_MARKETING);
        @chmod($arquivo, 0600);
    }

    private function comandoMarketing(AgentSession $session, string $tokenFile): array
    {
        $this->registraMcp($session);

        $permitidas = implode(',', array_map(
            fn ($t) => 'mcp__fbads__'.$t,
            ['conta_status', 'listar_criativos', 'listar_campanhas', 'criar_campanha', 'criar_conjunto', 'criar_anuncio', 'metricas'],
        ));

        $sistema = 'Você é o agente de marketing deste CRM e cuida da conta de Facebook Ads do usuário. '
            .'Fale português do Brasil, direto e sem enrolação. Você NÃO tem acesso a shell, arquivos ou internet: '
            .'suas únicas ações são as ferramentas mcp__fbads__*. Tudo que você criar nasce PAUSADO por decisão do '
            .'usuário — nunca prometa que algo está no ar, e ao terminar diga que ele precisa revisar e publicar. '
            .'Antes de criar um anúncio, use listar_criativos: as imagens são as que ele subiu pela tela, chamadas '
            .'"Criativo 1", "Criativo 2" e assim por diante. Orçamento vai na campanha OU no conjunto, nunca nos dois. '
            .'Se faltar informação essencial (objetivo, público, orçamento, link), pergunte em vez de inventar.';

        $args = self::CLAUDE.' -p --output-format stream-json --verbose'
            .' --tools ""'
            .' --disallowedTools '.escapeshellarg(implode(',', self::NATIVAS_BLOQUEADAS))
            .' --allowedTools '.escapeshellarg($permitidas)
            .' --append-system-prompt '.escapeshellarg($sistema);

        $sid = $session->claude_session_id;
        if ($sid && preg_match('/^[A-Za-z0-9\-]{8,}$/', $sid)) {
            $args .= ' --resume '.escapeshellarg($sid);
        }

        // cwd = área de trabalho vazia, NÃO o código-fonte: o agente não tem ferramenta
        // de arquivo, mas se um dia vazar uma, que não seja em cima do repositório.
        $inner = 'export CLAUDE_CODE_OAUTH_TOKEN="$(cat '.escapeshellarg($tokenFile).')"; '
            .'export HOME='.escapeshellarg(self::HOME_WWW).'; '
            .'cd '.escapeshellarg(self::WORKSPACE).' && exec '.$args;

        return ['sudo', '-u', self::RUN_AS_MARKETING, 'bash', '-lc', $inner];
    }

    private function runJob(AgentJob $job): void
    {
        $session = $job->session;
        $job->update(['status' => 'running', 'started_at' => now(), 'output' => '']);

        $marketing = $session->kind === 'marketing';
        $usuario = $marketing ? self::RUN_AS_MARKETING : self::RUN_AS;
        $tokenFile = $marketing ? storage_path('app/claude-token-www') : self::TOKEN_FILE;

        if (! $this->sincronizaCredencial($usuario, $tokenFile)) {
            $job->update([
                'status' => 'error',
                'error' => 'Nenhum token da IA configurado. Vá em Admin → Memória → Conexão da IA e conecte.',
                'finished_at' => now(),
            ]);

            return;
        }

        if ($marketing) {
            $cmd = $this->comandoMarketing($session, $tokenFile);
        } else {
            $cwd = $session->cwd ?: '/var/www/gestao';
            $sid = $session->claude_session_id;

            // Comando: roda como gestao-agent; prompt vai por STDIN (sem injeção). cwd/sid são escapados.
            $claudeArgs = self::CLAUDE.' -p --output-format stream-json --verbose --dangerously-skip-permissions';
            if ($sid && preg_match('/^[A-Za-z0-9\-]{8,}$/', $sid)) {
                $claudeArgs .= ' --resume '.escapeshellarg($sid);
            }
            // O token vai por ARQUIVO, não por argumento: em `ps` a linha de comando é pública.
            $inner = 'export CLAUDE_CODE_OAUTH_TOKEN="$(cat '.escapeshellarg($tokenFile).')"; '
                .'cd '.escapeshellarg($cwd).' && exec '.$claudeArgs;
            $cmd = ['sudo', '-u', $usuario, '-H', 'bash', '-lc', $inner];
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($proc)) {
            $job->update(['status' => 'error', 'error' => 'Não consegui iniciar o processo do agente.', 'finished_at' => now()]);

            return;
        }

        // Manda o pedido pelo stdin e fecha (claude lê o prompt do stdin).
        fwrite($pipes[0], $job->prompt);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $status = proc_get_status($proc);
        $job->update(['pid' => $status['pid'] ?? null]);

        $output = '';
        $buffer = '';
        $newSid = null;
        $lastCancelCheck = microtime(true);
        $lastSave = microtime(true);

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 1);

            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === '' || $chunk === false) {
                    continue;
                }
                if ($stream === $pipes[2]) {
                    // stderr: anexa direto (erros/avisos do CLI).
                    $output .= $chunk;

                    continue;
                }
                // stdout: stream-json (1 objeto por linha).
                $buffer .= $chunk;
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $nl);
                    $buffer = substr($buffer, $nl + 1);
                    [$text, $maybeSid] = $this->renderEvent($line);
                    if ($maybeSid) {
                        $newSid = $maybeSid;
                    }
                    if ($text !== '') {
                        $output .= $text;
                    }
                }
            }

            // Botão de matar (checa a cada ~1s).
            if (microtime(true) - $lastCancelCheck > 1.0) {
                $lastCancelCheck = microtime(true);
                if (AgentJob::where('id', $job->id)->value('cancel_requested')) {
                    $this->killTree($status['pid'] ?? null);
                    proc_terminate($proc, 9);
                    $output .= "\n\n⛔ Execução interrompida pelo usuário.\n";
                    $job->update(['status' => 'canceled', 'output' => $output, 'finished_at' => now()]);
                    $this->finishStreams($pipes, $proc);

                    return;
                }
            }

            // Salva o progresso periodicamente (streaming via polling).
            if (microtime(true) - $lastSave > 0.8) {
                $lastSave = microtime(true);
                AgentJob::where('id', $job->id)->update(['output' => $output]);
            }

            $st = proc_get_status($proc);
            if (! $st['running']) {
                // drena o que sobrou
                foreach ([$pipes[1], $pipes[2]] as $stream) {
                    while (($chunk = fread($stream, 8192)) !== '' && $chunk !== false) {
                        if ($stream === $pipes[1]) {
                            $buffer .= $chunk;
                        } else {
                            $output .= $chunk;
                        }
                    }
                }
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $nl);
                    $buffer = substr($buffer, $nl + 1);
                    [$text, $maybeSid] = $this->renderEvent($line);
                    if ($maybeSid) {
                        $newSid = $maybeSid;
                    }
                    $output .= $text;
                }
                break;
            }
        }

        $this->finishStreams($pipes, $proc);

        if ($newSid) {
            $session->update(['claude_session_id' => $newSid]);
        }
        $session->messages()->create(['role' => 'assistant', 'content' => $output]);
        $session->touch();
        $job->update(['status' => 'done', 'output' => $output, 'finished_at' => now()]);
        $this->info("job {$job->id} concluído");
    }

    /** "nome=Tráfego · objetivo=OUTCOME_TRAFFIC" — o suficiente para auditar sem abrir nada. */
    private function resumoArgs(array $in): string
    {
        $partes = [];
        foreach ($in as $chave => $valor) {
            if (is_array($valor)) {
                $valor = implode('/', array_map(fn ($v) => is_scalar($v) ? (string) $v : '…', $valor));
            }
            $valor = trim((string) $valor);
            if ($valor === '') {
                continue;
            }
            $partes[] = $chave.'='.mb_strimwidth(preg_replace('/\s+/', ' ', $valor) ?? '', 0, 60, '…');
            if (count($partes) >= 4) {
                break;
            }
        }

        return $partes ? ' '.implode(' · ', $partes) : '';
    }

    /** Converte um evento stream-json numa linha legível; devolve [texto, session_id?]. */
    private function renderEvent(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return ['', null];
        }
        $e = json_decode($line, true);
        if (! is_array($e)) {
            return ['', null];
        }
        $type = $e['type'] ?? '';

        if ($type === 'system' && ($e['subtype'] ?? '') === 'init') {
            return ["🟢 sessão iniciada\n", $e['session_id'] ?? null];
        }
        if ($type === 'assistant') {
            $out = '';
            foreach ($e['message']['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $out .= $block['text']."\n";
                } elseif (($block['type'] ?? '') === 'tool_use') {
                    $name = $block['name'] ?? 'tool';
                    $in = $block['input'] ?? [];
                    if ($name === 'Bash' && isset($in['command'])) {
                        $out .= "\n$ ".$in['command']."\n";
                    } elseif (str_starts_with($name, 'mcp__fbads__')) {
                        // Ferramenta do agente de marketing: o nome cru (mcp__fbads__criar_campanha)
                        // e um input vazio não dizem nada na tela — mostra a ação e os argumentos.
                        $out .= "\n[".substr($name, 12).$this->resumoArgs($in)."]\n";
                    } else {
                        $brief = $in['file_path'] ?? ($in['path'] ?? ($in['pattern'] ?? ''));
                        $out .= "\n[".$name.($brief ? ' '.$brief : '')."]\n";
                    }
                }
            }

            return [$out, $e['session_id'] ?? null];
        }
        if ($type === 'user') {
            // resultado de ferramenta (ex.: saída de bash) — anexa truncado.
            foreach ($e['message']['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'tool_result') {
                    $c = $block['content'] ?? '';
                    if (is_array($c)) {
                        $c = collect($c)->pluck('text')->filter()->implode("\n");
                    }
                    $c = (string) $c;
                    if (trim($c) !== '') {
                        // Sentinelas: sem elas a tela não tem como distinguir a saída de um
                        // comando do texto que o agente escreveu — virava tudo um parágrafo só.
                        return ["⟦saída⟧\n".mb_substr($c, 0, 4000)."\n⟦fim⟧\n", null];
                    }
                }
            }

            return ['', null];
        }
        if ($type === 'result') {
            return ['', $e['session_id'] ?? null];
        }

        return ['', null];
    }

    private function finishStreams(array $pipes, $proc): void
    {
        foreach ([1, 2] as $i) {
            if (isset($pipes[$i]) && is_resource($pipes[$i])) {
                @fclose($pipes[$i]);
            }
        }
        @proc_close($proc);
    }

    /** Mata a árvore de processos do claude (filhos de bash) ao cancelar. */
    private function killTree(?int $pid): void
    {
        if (! $pid) {
            return;
        }
        @exec('pkill -9 -P '.((int) $pid));
        @posix_kill($pid, 9);
    }
}
