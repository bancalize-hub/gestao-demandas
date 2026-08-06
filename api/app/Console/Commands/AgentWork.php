<?php

namespace App\Console\Commands;

use App\Models\AgentJob;
use App\Models\AgentSession;
use App\Models\WaAccount;
use App\Support\Claude;
use App\Support\Tenancy;
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

    private const CLAUDE = '/usr/local/bin/claude';

    /** Espelho do token do painel, legível só pelo gestao-agent (ver sincronizaCredencial). */
    private const TOKEN_FILE = '/home/'.self::RUN_AS.'/.claude-token';

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
    /** O que transforma o agente da VPS num agente de marketing: só o texto abaixo. */
    private function instrucoesMarketing(AgentSession $session): string
    {
        $empresa = (int) $session->company_id;

        // O número de destino NUNCA pode ser digitado pelo modelo: em 03/08/2026 ele
        // inverteu dois dígitos e os 9 anúncios da conta apontaram para um WhatsApp que
        // não era do usuário. Vem do banco, pronto para copiar.
        // company_id explícito: este comando roda no worker, sem empresa vinculada, e aí
        // o escopo global é inerte — sem o filtro, sairia o número de OUTRA empresa.
        $numero = WaAccount::where('company_id', $empresa)
            ->orderByRaw("role = 'primary' desc")
            ->value('phone');
        $destino = $numero
            ? "O WhatsApp que recebe os leads é o {$numero}. NUNCA digite outro número: o link do
        anúncio é exatamente `https://api.whatsapp.com/send?phone={$numero}&text=Criativo+N`, com N
        igual ao número do criativo usado. Esse texto pré-preenchido é o que faz o CRM saber de qual
        anúncio veio cada lead — se mudar o formato, a origem do lead se perde."
            : 'ATENÇÃO: a empresa não tem número de WhatsApp cadastrado — pergunte ao usuário qual é
        o link de destino antes de criar qualquer anúncio.';

        return <<<TXT
        Nesta sessão você é o agente de MARKETING: cuida da conta de Facebook Ads do usuário.
        Fale português do Brasil, direto e sem enrolação.

        Para agir no Facebook use SEMPRE o CLI do próprio sistema, nunca chamadas HTTP na mão:

          cd /var/www/gestao/api && php artisan fbads <acao> --empresa={$empresa} [opções]

        Ações: conta | criativos | campanhas | criar-campanha | criar-conjunto | criar-anuncio | metricas
        Veja `php artisan fbads --help` para todas as opções. A saída é JSON.

        REGRA QUE NÃO SE NEGOCIA: tudo que o CLI cria nasce PAUSADO, e é assim de propósito.
        Nunca diga que um anúncio está no ar. Ao terminar, diga em uma linha o que foi criado
        e que o usuário precisa revisar e publicar no Gerenciador de Anúncios.

        Antes de criar anúncio, rode `fbads criativos`: as imagens são as que o usuário subiu
        pela tela, chamadas "Criativo 1", "Criativo 2"…, e as observações dele dizem para que
        serve cada uma. Referencie pelo nome, ex.: --criativo="Criativo 3".

        {$destino}

        O orçamento fica na campanha OU no conjunto, nunca nos dois. Se faltar informação
        essencial (objetivo, público, orçamento, link), pergunte em vez de inventar — errar
        aqui gasta dinheiro do usuário.

        Não edite o código do sistema nesta sessão: seu trabalho é operar a conta de anúncios.
        TXT;
    }

    private function runJob(AgentJob $job): void
    {
        $session = $job->session;
        if ($session->company_id) {
            app(Tenancy::class)->set((int) $session->company_id);
        }
        $job->update(['status' => 'running', 'started_at' => now(), 'output' => '']);

        $marketing = $session->kind === 'marketing';

        if (! $this->sincronizaCredencial(self::RUN_AS, self::TOKEN_FILE)) {
            $job->update([
                'status' => 'error',
                'error' => 'Nenhum token da IA configurado. Vá em Admin → Memória → Conexão da IA e conecte.',
                'finished_at' => now(),
            ]);

            return;
        }

        $cwd = $session->cwd ?: '/var/www/gestao';
        $sid = $session->claude_session_id;

        // Comando: roda como gestao-agent; prompt vai por STDIN (sem injeção). cwd/sid são escapados.
        $claudeArgs = self::CLAUDE.' -p --output-format stream-json --verbose --dangerously-skip-permissions';
        if ($sid && preg_match('/^[A-Za-z0-9\-]{8,}$/', $sid)) {
            $claudeArgs .= ' --resume '.escapeshellarg($sid);
        }
        // Marketing é o MESMO motor do /agente (assinatura, shell) — só muda a instrução:
        // em vez de operar a VPS, ele age no Facebook pelo CLI `php artisan fbads`.
        if ($marketing) {
            $claudeArgs .= ' --append-system-prompt '.escapeshellarg($this->instrucoesMarketing($session));
        }
        // O token vai por ARQUIVO, não por argumento: em `ps` a linha de comando é pública.
        $inner = 'export CLAUDE_CODE_OAUTH_TOKEN="$(cat '.escapeshellarg(self::TOKEN_FILE).')"; '
            .'cd '.escapeshellarg($cwd).' && exec '.$claudeArgs;
        $cmd = ['sudo', '-u', self::RUN_AS, '-H', 'bash', '-lc', $inner];

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
