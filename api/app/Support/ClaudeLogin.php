<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * Login na assinatura Claude pela tela, sem SSH.
 *
 * `claude setup-token` é interativo: exige um terminal de verdade, imprime uma URL de
 * autorização e depois fica esperando o código colado. Aqui ele roda dentro de um pty
 * (`script`) com a entrada vinda de um FIFO — assim o passo 1 (pegar a URL) e o passo 2
 * (entregar o código) podem acontecer em requisições HTTP diferentes.
 *
 * Detalhes que custaram a descobrir:
 * - `script` executa o comando pelo shell do usuário; o do www-data é `nologin`, que
 *   responde "This account is currently not available" e mata o processo. Daí o SHELL.
 * - sem alguém segurando a ponta de escrita do FIFO, o CLI lê EOF na hora e desiste;
 *   por isso o `sleep` fica com ele aberto durante a sessão.
 * - o pty nasce com 80 colunas e QUEBRA a URL no meio; `stty cols` evita remontagem frágil.
 */
class ClaudeLogin
{
    /** Tempo máximo de uma sessão de login (o CLI fica esperando o código nesse período). */
    private const TTL_SEGUNDOS = 600;

    private static function dir(string $id): string
    {
        return storage_path('app/claude-login/'.$id);
    }

    /** Só o formato esperado de id de sessão — nunca cai em caminho fora do diretório. */
    private static function valido(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{16}$/', $id);
    }

    /** Passo 1: sobe o CLI e devolve a URL de autorização. */
    public static function iniciar(): array
    {
        self::limparAntigas();

        $id = bin2hex(random_bytes(8));
        $dir = self::dir($id);
        @mkdir($dir.'/home', 0700, true);

        $fifo = $dir.'/in';
        $out = $dir.'/out';
        $bin = (string) config('services.claude.bin');
        $ttl = self::TTL_SEGUNDOS;

        // setsid: o processo sobrevive ao fim desta requisição do PHP-FPM.
        Process::run(sprintf(
            'mkfifo %s && (setsid sleep %d > %s &) && (HOME=%s SHELL=/bin/bash setsid script -qec %s /dev/null < %s > %s 2>&1 &)',
            escapeshellarg($fifo),
            $ttl,
            escapeshellarg($fifo),
            escapeshellarg($dir.'/home'),
            escapeshellarg('stty cols 400 2>/dev/null; exec '.$bin.' setup-token'),
            escapeshellarg($fifo),
            escapeshellarg($out),
        ));

        // A URL costuma sair em 2-4s; 20s cobre uma VPS ocupada.
        $url = null;
        for ($i = 0; $i < 40 && ! $url; $i++) {
            usleep(500_000);
            $url = self::extrair('~https://claude\.com/[^\s\x1b]+~', self::saida($out));
        }

        if (! $url) {
            self::encerrar($id);

            return ['ok' => false, 'mensagem' => 'Não consegui iniciar o login: '.self::trecho(self::saida($out))];
        }

        return ['ok' => true, 'sessao' => $id, 'url' => $url];
    }

    /** Passo 2: entrega o código colado pelo usuário e colhe o token. */
    public static function concluir(string $id, string $codigo): array
    {
        if (! self::valido($id) || ! is_dir(self::dir($id))) {
            return ['ok' => false, 'mensagem' => 'Sessão de login expirada — comece de novo.'];
        }

        $dir = self::dir($id);
        $out = $dir.'/out';

        // Escrita direta no FIFO (nada de shell): o código vem do usuário.
        @file_put_contents($dir.'/in', $codigo."\n");

        $token = null;
        for ($i = 0; $i < 60 && ! $token; $i++) {
            usleep(500_000);
            $token = self::extrair('~sk-ant-oat[A-Za-z0-9_\-]+~', self::saida($out));
        }

        $saida = self::saida($out);
        self::encerrar($id);

        if (! $token) {
            return ['ok' => false, 'mensagem' => 'Não recebi o token. O CLI respondeu: '.self::trecho($saida)];
        }

        Claude::salvarToken($token);

        return ['ok' => true] + Claude::testar();
    }

    /** Mata a sessão e apaga o diretório (o token nunca fica em disco fora do lugar dele). */
    public static function encerrar(string $id): void
    {
        if (! self::valido($id)) {
            return;
        }
        Process::run('rm -rf '.escapeshellarg(self::dir($id)));
    }

    /** Sessões abandonadas não podem virar lixo (nem CLI pendurado) no servidor. */
    private static function limparAntigas(): void
    {
        $base = storage_path('app/claude-login');
        if (! is_dir($base)) {
            return;
        }
        foreach ((array) glob($base.'/*') as $dir) {
            if (is_dir($dir) && filemtime($dir) < time() - self::TTL_SEGUNDOS) {
                Process::run('rm -rf '.escapeshellarg($dir));
            }
        }
    }

    /** Saída do pty sem os códigos de escape do terminal. */
    private static function saida(string $arquivo): string
    {
        $raw = is_file($arquivo) ? (string) @file_get_contents($arquivo) : '';

        return preg_replace('/\x1b\[[0-9;?]*[a-zA-Z]|\r/', '', $raw) ?? '';
    }

    private static function extrair(string $regex, string $texto): ?string
    {
        return preg_match($regex, $texto, $m) ? $m[0] : null;
    }

    private static function trecho(string $texto): string
    {
        $limpo = trim(preg_replace('/\s+/', ' ', $texto) ?? '');

        return mb_substr($limpo, -300) ?: '(sem saída)';
    }
}
