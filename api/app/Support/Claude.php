<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class Claude
{
    /** Enquanto esta chave existe, a IA está fora — ver {@see self::indisponivel()}. */
    private const DOWN_KEY = 'claude:indisponivel';

    /**
     * Token gravado pelo painel. Fica fora do `.env` de propósito: o PHP-FPM não escreve
     * lá (arquivo do root) e trocar o .env ainda exigiria `config:cache` na mão — aqui
     * o token novo passa a valer no próximo pedido, sem deploy.
     */
    private static function tokenPath(): string
    {
        return storage_path('app/claude-token');
    }

    /** Token em uso: o do painel tem prioridade sobre o do .env (é o mais recente). */
    public static function token(): ?string
    {
        $path = self::tokenPath();
        if (is_file($path)) {
            $salvo = trim((string) @file_get_contents($path));
            if ($salvo !== '') {
                return $salvo;
            }
        }

        return config('services.claude.oauth_token') ?: null;
    }

    /** Grava o token do painel e devolve a IA ao ar (limpa a trégua do circuit breaker). */
    public static function salvarToken(string $token): void
    {
        $path = self::tokenPath();
        file_put_contents($path, trim($token)."\n");
        @chmod($path, 0600);
        Cache::forget(self::DOWN_KEY);
        Evolution::log('claude.token_atualizado', ['origem' => 'painel']);
    }

    /** De onde veio o token em uso (para a tela explicar). */
    public static function origemDoToken(): string
    {
        if (is_file(self::tokenPath()) && trim((string) @file_get_contents(self::tokenPath())) !== '') {
            return 'painel';
        }

        return config('services.claude.oauth_token') ? 'env' : 'nenhum';
    }

    /**
     * Como a credencial chega ao CLI. Token de assinatura (`sk-ant-oat01-…`) vai em
     * CLAUDE_CODE_OAUTH_TOKEN; chave de API (`sk-ant-api03-…`) em ANTHROPIC_API_KEY.
     * A variável não usada vai como `false` de propósito: o Symfony a REMOVE do
     * ambiente do filho, senão uma credencial velha herdada do PHP-FPM venceria a nova.
     */
    private static function ambiente(string $token): array
    {
        $apiKey = str_starts_with($token, 'sk-ant-api');

        return [
            'CLAUDE_CODE_OAUTH_TOKEN' => $apiKey ? false : $token,
            'ANTHROPIC_API_KEY' => $apiKey ? $token : false,
            'HOME' => storage_path('app/claude-home'),
        ];
    }

    /** A credencial em si está ruim (token revogado, sem acesso, não logado). */
    private static function falhaDeCredencial(string $saida): bool
    {
        return (bool) preg_match(
            '/401|authenticat|revoked|expired|unauthorized|not logged in|disabled|subscription access|invalid.{0,10}api key/i',
            $saida
        );
    }

    /**
     * Vale segurar as chamadas por uns minutos?
     *
     * O caso real (03/09 → 07/09): a mensagem era "organization has disabled Claude
     * subscription access" e "session limit" — nenhuma casava com o teste de credencial,
     * então a trégua nunca armava e o CRM respawnava o CLI ~21 mil vezes por dia, em
     * silêncio, numa VPS de 2 vCPU. Limite de uso entra aqui junto: insistir a cada
     * 2 min não muda o resultado, só multiplica processo.
     */
    private static function deveSuspender(string $saida): bool
    {
        return self::falhaDeCredencial($saida)
            || (bool) preg_match('/session limit|usage limit|rate limit|overloaded|too many requests|\b429\b/i', $saida);
    }

    /**
     * Testa a credencial de verdade (uma chamada mínima) e devolve o que o CLI disse.
     * É o que transforma "a IA não respondeu" em uma causa na tela.
     */
    public static function testar(): array
    {
        Cache::forget(self::DOWN_KEY); // um teste manual sempre tenta de novo
        $token = self::token();
        if (! $token) {
            return ['ok' => false, 'mensagem' => 'Nenhum token configurado.'];
        }

        $res = Process::timeout(60)
            ->env(self::ambiente($token))
            ->input('Responda apenas: ok')
            ->run([config('services.claude.bin'), '-p']);

        $saida = trim($res->output()."\n".$res->errorOutput());

        if ($res->successful() && ! self::falhaDeCredencial($saida)) {
            return ['ok' => true, 'mensagem' => mb_substr($saida, 0, 200) ?: 'ok'];
        }

        // Exit 0 com "Not logged in" no texto também é falha — o CLI não usa código de saída
        // para credencial ruim, e sem isto o teste diria "funcionando" com a IA fora.
        self::marcarFora(mb_substr($saida, 0, 200));

        return ['ok' => false, 'mensagem' => mb_substr($saida, 0, 300) ?: "CLI saiu com código {$res->exitCode()}"];
    }

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
        $token = self::token();
        if (! $token) {
            self::marcarFora('Nenhum token da IA configurado (painel ou .env)');

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
            ->env(self::ambiente($token))
            ->input($prompt)
            ->run([config('services.claude.bin'), '-p']);

        // O CLI escreve o erro de autenticação no STDOUT — e com exit 0 em alguns casos
        // ("Not logged in · Please run /login"). Olhar só o código de saída fazia a falha
        // de credencial passar por resposta válida.
        $saida = trim($res->output()."\n".$res->errorOutput());
        $auth = self::falhaDeCredencial($saida);

        if ($res->successful() && ! $auth) {
            return trim($res->output());
        }

        Evolution::log('claude.falha', [
            'exit' => $res->exitCode(),
            'credencial' => $auth,
            'saida' => mb_substr($saida, 0, 300),
        ], 'error');

        if (self::deveSuspender($saida)) {
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
