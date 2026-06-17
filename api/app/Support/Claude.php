<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

class Claude
{
    /** Roda o CLI claude (assinatura) como subprocesso e retorna o texto, ou null. */
    public static function run(string $prompt, int $timeout = 120): ?string
    {
        $token = config('services.claude.oauth_token');
        if (! $token) {
            return null;
        }

        $res = Process::timeout($timeout)
            ->env([
                'CLAUDE_CODE_OAUTH_TOKEN' => $token,
                'HOME' => storage_path('app/claude-home'),
            ])
            ->run([config('services.claude.bin'), '-p', $prompt]);

        return $res->successful() ? trim($res->output()) : null;
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
