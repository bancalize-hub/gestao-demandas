<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\WaAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fotos de perfil dos contatos.
 *
 * Duas regras que valem para tudo aqui:
 *
 * 1. **O binário fica no nosso disco.** A URL do `pps.whatsapp.net` tem prazo (`oe=`) e
 *    responde 403 depois de alguns dias. Guardar só a URL, como era antes, fazia a foto de
 *    TODO mundo sumir com o tempo. A coluna `avatar` continua guardando a origem, mas quem
 *    a tela consome é o arquivo.
 * 2. **A foto vem sempre da Evolution (Baileys).** A API oficial da Meta não entrega foto
 *    de perfil de contato — nem existe endpoint para isso. Em compensação, uma instância
 *    Evolution CONECTADA consulta a foto de qualquer telefone, inclusive de contatos que
 *    chegaram pelo número oficial. Basta um número da empresa estar logado.
 */
class Avatars
{
    /** Onde o binário mora. Mesmo padrão do cache de mídia (storage/app/wa-media). */
    public static function pathFor(Conversation $conversation): string
    {
        return storage_path('app/wa-avatars/'.$conversation->id);
    }

    /** Já temos a foto no disco? */
    public static function temArquivo(Conversation $conversation): bool
    {
        return is_file(self::pathFor($conversation));
    }

    /** Tipo da imagem lido dos bytes — o WhatsApp manda jpeg, mas não custa conferir. */
    public static function mimeDe(string $path): string
    {
        $info = @getimagesize($path);

        return $info['mime'] ?? 'image/jpeg';
    }

    /**
     * Baixa a foto da URL guardada em `avatar` e grava no disco.
     *
     * Devolve false (sem estourar) quando não há URL, quando ela já venceu ou quando o
     * corpo não é imagem — o chamador decide o que fazer com o miss.
     */
    public static function baixar(Conversation $conversation): bool
    {
        $url = trim((string) $conversation->avatar);
        if ($url === '' || ! str_starts_with($url, 'http')) {
            return false;
        }

        try {
            $res = Http::timeout(15)->get($url);
        } catch (\Throwable $e) {
            return false;
        }

        return self::gravar($conversation, $res);
    }

    /** Grava o corpo da resposta se ele for mesmo uma imagem. */
    public static function gravar(Conversation $conversation, Response $res): bool
    {
        if (! $res->successful()) {
            return false;
        }

        $bytes = $res->body();
        // Uma imagem de perfil não tem 200 bytes; corpo minúsculo é página de erro.
        if (strlen($bytes) < 512) {
            return false;
        }

        $dir = storage_path('app/wa-avatars');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $path = self::pathFor($conversation);
        // Escreve em temporário e move: uma leitura concorrente nunca pega meio arquivo.
        $tmp = $path.'.tmp';
        if (@file_put_contents($tmp, $bytes) === false) {
            return false;
        }
        if (! @getimagesize($tmp)) {
            @unlink($tmp);

            return false;
        }
        @rename($tmp, $path);

        return is_file($path);
    }

    /**
     * A instância Evolution CONECTADA da empresa, se houver.
     *
     * Serve qualquer número logado: para perguntar a foto de um telefone, o Baileys não
     * exige que a conversa tenha acontecido naquele número. É isso que permite ter foto
     * de contato que só falou com o número oficial da Meta.
     */
    public static function instanciaConectada(?int $companyId = null): ?string
    {
        $q = WaAccount::withoutGlobalScopes()
            ->where('provider', 'evolution')
            ->where('state', 'open')
            ->whereNotNull('instance');

        if ($companyId) {
            $q->where('company_id', $companyId);
        }

        return $q->value('instance');
    }

    /**
     * Pergunta à Evolution a foto de vários telefones de uma vez e grava as que vierem.
     *
     * @param  \Illuminate\Support\Collection<int, Conversation>  $conversas
     * @return int quantas fotos novas entraram
     */
    public static function buscarNaEvolution($conversas, string $instancia): int
    {
        $porNumero = $conversas
            ->filter(fn ($c) => filled($c->phone))
            ->keyBy(fn ($c) => preg_replace('/\D/', '', (string) $c->phone))
            ->filter(fn ($c, $num) => $num !== '');

        if ($porNumero->isEmpty()) {
            return 0;
        }

        $base = rtrim((string) config('services.evolution.url'), '/');
        $key = (string) config('services.evolution.key');

        $respostas = Http::pool(fn ($pool) => $porNumero->keys()->map(
            fn ($num) => $pool->as((string) $num)
                ->baseUrl($base)
                ->withHeaders(['apikey' => $key])
                ->timeout(15)
                ->post("/chat/fetchProfilePictureUrl/{$instancia}", ['number' => (string) $num])
        )->all());

        $novas = 0;
        foreach ($porNumero as $num => $conversa) {
            $r = $respostas[(string) $num] ?? null;
            $url = ($r instanceof Response && $r->successful()) ? $r->json('profilePictureUrl') : null;
            if (! $url) {
                continue;
            }

            // Baixa AGORA: guardar a URL para baixar depois é apostar contra o relógio dela.
            $conversa->avatar = $url;
            try {
                $img = Http::timeout(15)->get($url);
            } catch (\Throwable $e) {
                continue;
            }
            if (self::gravar($conversa, $img)) {
                $conversa->saveQuietly();
                $novas++;
            }
        }

        return $novas;
    }
}
