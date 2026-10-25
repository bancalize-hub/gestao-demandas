<?php

namespace App\Support;

use App\Models\MarketingAutopilot;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gera imagem de anúncio com IA — o provedor é escolhido no painel (OpenAI, Google ou
 * Ideogram) e pode ser trocado sem mexer no resto do piloto automático.
 *
 * Toda saída é normalizada para PNG 1080×1350 (4:5, o formato de feed que a conta usa):
 * cada provedor devolve um tamanho diferente, e mandar para a Meta o que veio cru faria
 * cada criativo nascer com um corte diferente nos posicionamentos.
 */
class ImageGen
{
    public const LARGURA = 1080;

    public const ALTURA = 1350;

    /** Modelo usado quando o painel deixa o campo vazio. */
    public const MODELO_PADRAO = [
        'openai' => 'gpt-image-1',
        'google' => 'gemini-2.5-flash-image',
        'ideogram' => 'V_3',
    ];

    public function __construct(
        private string $provedor,
        private string $chave,
        private ?string $modelo = null,
    ) {}

    public static function make(MarketingAutopilot $cfg): self
    {
        if (! in_array($cfg->image_provider, MarketingAutopilot::PROVEDORES, true) || blank($cfg->image_api_key)) {
            throw new RuntimeException('Gerador de imagem não configurado: escolha o provedor e informe a chave no painel do piloto automático.');
        }

        return new self($cfg->image_provider, $cfg->image_api_key, $cfg->image_model ?: null);
    }

    public function modelo(): string
    {
        return $this->modelo ?: self::MODELO_PADRAO[$this->provedor];
    }

    /** @return string bytes PNG 1080×1350 */
    public function gerar(string $prompt): string
    {
        $bruto = match ($this->provedor) {
            'openai' => $this->openai($prompt),
            'google' => $this->google($prompt),
            'ideogram' => $this->ideogram($prompt),
        };

        return self::normalizar($bruto);
    }

    /**
     * Confere a chave sem gerar imagem (gerar custa dinheiro). O Ideogram não tem endpoint
     * barato de leitura — para ele a resposta é "não dá para testar sem gerar".
     *
     * @return array{ok: bool, mensagem: string}
     */
    public function testar(): array
    {
        try {
            $res = match ($this->provedor) {
                'openai' => Http::timeout(20)->withToken($this->chave)->get('https://api.openai.com/v1/models/'.$this->modelo()),
                'google' => Http::timeout(20)->get('https://generativelanguage.googleapis.com/v1beta/models/'.$this->modelo(), ['key' => $this->chave]),
                'ideogram' => null,
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensagem' => 'Sem resposta do provedor: '.$e->getMessage()];
        }

        if ($res === null) {
            return ['ok' => true, 'mensagem' => 'Chave salva. O Ideogram não tem teste sem gerar imagem — use "Simular agora" para conferir.'];
        }
        if ($res->successful()) {
            return ['ok' => true, 'mensagem' => 'Chave válida e modelo '.$this->modelo().' disponível.'];
        }

        return ['ok' => false, 'mensagem' => self::erro($res->json(), $res->status())];
    }

    private function openai(string $prompt): string
    {
        $res = Http::timeout(240)->withToken($this->chave)->post('https://api.openai.com/v1/images/generations', [
            'model' => $this->modelo(),
            'prompt' => $prompt,
            'size' => '1024x1536',
            'quality' => 'high',
            'n' => 1,
        ]);
        $b64 = $res->json('data.0.b64_json');
        if (! $res->successful() || ! $b64) {
            throw new RuntimeException('OpenAI recusou: '.self::erro($res->json(), $res->status()));
        }

        return base64_decode($b64);
    }

    private function google(string $prompt): string
    {
        $res = Http::timeout(240)->post(
            'https://generativelanguage.googleapis.com/v1beta/models/'.$this->modelo().':generateContent?key='.urlencode($this->chave),
            [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'responseModalities' => ['IMAGE'],
                    'imageConfig' => ['aspectRatio' => '4:5'],
                ],
            ],
        );
        foreach ((array) $res->json('candidates.0.content.parts') as $parte) {
            if ($dados = $parte['inlineData']['data'] ?? null) {
                return base64_decode($dados);
            }
        }

        throw new RuntimeException('Google não devolveu imagem: '.self::erro($res->json(), $res->status()));
    }

    private function ideogram(string $prompt): string
    {
        $res = Http::timeout(240)
            ->withHeaders(['Api-Key' => $this->chave])
            ->asMultipart()
            ->post('https://api.ideogram.ai/v1/ideogram-v3/generate', [
                ['name' => 'prompt', 'contents' => $prompt],
                ['name' => 'aspect_ratio', 'contents' => '4x5'],
                ['name' => 'rendering_speed', 'contents' => 'QUALITY'],
                ['name' => 'magic_prompt', 'contents' => 'OFF'],
            ]);
        $url = $res->json('data.0.url');
        if (! $res->successful() || ! $url) {
            throw new RuntimeException('Ideogram recusou: '.self::erro($res->json(), $res->status()));
        }

        // A URL do Ideogram expira: baixar na hora.
        $img = Http::timeout(120)->get($url);
        if (! $img->successful()) {
            throw new RuntimeException('Não consegui baixar a imagem do Ideogram (HTTP '.$img->status().').');
        }

        return $img->body();
    }

    /** Corta no centro para 4:5 e redimensiona para 1080×1350, em PNG. */
    public static function normalizar(string $bytes): string
    {
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            throw new RuntimeException('O provedor devolveu algo que não é imagem.');
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $alvo = self::LARGURA / self::ALTURA;
        if ($w / $h > $alvo) {
            $cw = (int) round($h * $alvo);
            $ch = $h;
        } else {
            $cw = $w;
            $ch = (int) round($w / $alvo);
        }
        $x = (int) (($w - $cw) / 2);
        $y = (int) (($h - $ch) / 2);

        $dst = imagecreatetruecolor(self::LARGURA, self::ALTURA);
        imagecopyresampled($dst, $src, 0, 0, $x, $y, self::LARGURA, self::ALTURA, $cw, $ch);

        ob_start();
        imagepng($dst, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return $png;
    }

    private static function erro(mixed $json, int $status): string
    {
        $msg = is_array($json)
            ? ($json['error']['message'] ?? $json['message'] ?? $json['detail'] ?? json_encode($json))
            : null;

        return mb_substr((string) ($msg ?: "HTTP {$status}"), 0, 300);
    }
}
