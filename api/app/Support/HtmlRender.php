<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Transforma a peça que o designer escreveu em HTML num PNG de feed (1080×1350 em 2x =
 * 2160×2700, o mesmo tamanho dos criativos que já funcionam na conta).
 *
 * É o motor padrão do designer: os vencedores da conta são peças tipográficas (jornal,
 * comunicado, classificado), e em HTML o texto sai exatamente como escrito — gerador de
 * imagem troca letra e acento. As fontes da marca estão instaladas no servidor.
 */
class HtmlRender
{
    public const LARGURA = 1080;

    public const ALTURA = 1350;

    public static function disponivel(): bool
    {
        return is_executable((string) config('services.chrome.bin'));
    }

    public static function png(string $html, string $saida): void
    {
        if (! self::disponivel()) {
            throw new RuntimeException('Navegador para renderizar HTML não instalado ('.config('services.chrome.bin').').');
        }
        if (! is_file($html)) {
            throw new RuntimeException("Arquivo não encontrado: {$html}");
        }
        @unlink($saida);

        $res = Process::timeout(60)
            ->env(['HOME' => sys_get_temp_dir()])
            ->run([
                (string) config('services.chrome.bin'),
                '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--allow-file-access-from-files',
                '--force-device-scale-factor=2', '--window-size='.self::LARGURA.','.self::ALTURA,
                // Dá tempo de fonte e imagem carregarem antes da foto.
                '--virtual-time-budget=4000',
                '--screenshot='.$saida,
                'file://'.$html,
            ]);

        if (! is_file($saida) || filesize($saida) < 1000) {
            throw new RuntimeException('O navegador não gerou a imagem: '.trim($res->errorOutput() ?: $res->output()));
        }
    }
}
