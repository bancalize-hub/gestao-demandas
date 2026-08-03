<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class Claude
{
    /** Enquanto esta chave existe, a IA está fora — ver {@see self::indisponivel()}. */
    private const DOWN_KEY = 'claude:indisponivel';

    /**
     * Por que a IA está fora agora (null = está de pé).
     *
     * O caso real: o token OAuth foi revogado e TUDO que depende de IA parou —
     * resposta automática, sugestão, memória, campanha — em silêncio, porque a
     * falha só virava `return null`. Ninguém percebeu por 4 dias.
     */
    public static function indisponivel(): ?string
    {
        return Cache::get(self::DOWN_KEY);
    }

    /** Roda o CLI claude (assinatura) como subprocesso e retorna o texto, ou null. */
    public static function run(string $prompt, int $timeout = 120): ?string
    {
        $token = config('services.claude.oauth_token');
        if (! $token) {
            self::marcarFora('CLAUDE_CODE_OAUTH_TOKEN não configurado no .env');

            return null;
        }

        // Credencial revogada derruba todas as chamadas: sem esta trégua, cada conversa
        // pendente respawnava o CLI a cada 2 min só para tomar 401 de novo (dezenas de
        // processos por minuto numa VPS de 2 vCPU).
        if (self::indisponivel()) {
            return null;
        }

        // Prompt via STDIN, nunca como argumento: prompt grande (conversa longa +
        // base de conhecimento) estourava o limite de ~128KB por argumento do Linux
        // (proc_open: "Argument list too long") e derrubava quem chamasse.
        $res = Process::timeout($timeout)
            ->env([
                'CLAUDE_CODE_OAUTH_TOKEN' => $token,
                'HOME' => storage_path('app/claude-home'),
            ])
            ->input($prompt)
            ->run([config('services.claude.bin'), '-p']);

        if ($res->successful()) {
            return trim($res->output());
        }

        // O CLI escreve o erro de autenticação no STDOUT com exit 1 — juntar os dois
        // é o que faz a causa aparecer no log em vez de "a IA não respondeu".
        $saida = trim($res->output()."\n".$res->errorOutput());
        $auth = (bool) preg_match('/401|authenticat|revoked|expired|unauthorized|login/i', $saida);

        Evolution::log('claude.falha', [
            'exit' => $res->exitCode(),
            'credencial' => $auth,
            'saida' => mb_substr($saida, 0, 300),
        ], 'error');

        if ($auth) {
            self::marcarFora(mb_substr($saida, 0, 200));
        }

        return null;
    }

    /** Registra a queda e segura novas tentativas por 10 min (renovar o token limpa). */
    private static function marcarFora(string $motivo): void
    {
        if (! self::indisponivel()) {
            Evolution::log('claude.fora_do_ar', ['motivo' => $motivo], 'error');
        }
        Cache::put(self::DOWN_KEY, $motivo, now()->addMinutes(10));
    }

    /** Extrai um JSON (array/obj) da saída do Claude, tolerando cercas ```json. */
    public static function json(?string $output): mixed
    {
        if (! $output) {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $output, $m)) {
            $output = $m[1];
        }
        // primeiro [ ... ] ou { ... }
        if (preg_match('/(\[.*\]|\{.*\})/s', $output, $m)) {
            $output = $m[1];
        }

        return json_decode(trim($output), true);
    }
}
