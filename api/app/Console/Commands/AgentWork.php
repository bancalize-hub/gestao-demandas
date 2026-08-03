<?php

namespace App\Console\Commands;

use App\Models\AgentJob;
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

    private function runJob(AgentJob $job): void
    {
        $session = $job->session;
        $job->update(['status' => 'running', 'started_at' => now(), 'output' => '']);

        $cwd = $session->cwd ?: '/var/www/gestao';
        $sid = $session->claude_session_id;

        // Comando: roda como gestao-agent; prompt vai por STDIN (sem injeção). cwd/sid são escapados.
        $claudeArgs = self::CLAUDE.' -p --output-format stream-json --verbose --dangerously-skip-permissions';
        if ($sid && preg_match('/^[A-Za-z0-9\-]{8,}$/', $sid)) {
            $claudeArgs .= ' --resume '.escapeshellarg($sid);
        }
        $inner = 'cd '.escapeshellarg($cwd).' && exec '.$claudeArgs;
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
