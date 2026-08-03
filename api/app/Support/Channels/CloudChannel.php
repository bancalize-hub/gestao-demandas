<?php

namespace App\Support\Channels;

use App\Models\WaAccount;
use App\Support\Evolution;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Canal OFICIAL: WhatsApp Cloud API (Graph API da Meta).
 *
 * Diferenças de fundo em relação à Evolution, que explicam o desenho daqui:
 *
 *  - **Janela de 24h**: texto livre só é aceito nas 24h seguintes à última mensagem
 *    DO CLIENTE. Fora dela, só template aprovado ({@see sendTemplate}).
 *  - **Mídia em duas etapas**: sobe o arquivo (`/media` → id) e envia o id. Para receber,
 *    o webhook traz um id de mídia que precisa ser resolvido em URL e baixado com o token.
 *  - **Sem etiquetas e sem histórico**: a Cloud API não expõe as labels do app Business
 *    nem permite buscar mensagens antigas (o histórico só chega no onboarding de
 *    coexistência, pelo webhook `history`).
 *
 * Credenciais são POR CONTA (multi-empresa), nunca do .env.
 */
class CloudChannel implements WaChannel
{
    public function __construct(private WaAccount $account) {}

    public function account(): WaAccount
    {
        return $this->account;
    }

    private function version(): string
    {
        return $this->account->graph_version ?: (string) config('services.wa_cloud.graph_version', 'v21.0');
    }

    private function http(int $timeout = 20): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/'.$this->version())
            ->withToken((string) $this->account->access_token)
            ->timeout($timeout);
    }

    /**
     * Tradução dos erros da Meta que mais aparecem no dia a dia — é o que transforma
     * "não chegou" em uma causa acionável direto no log.
     */
    private static function dicaDoErro(int $code, int $subcode): string
    {
        return match (true) {
            $code === 131047 => 'janela de 24h fechada — só template aprovado',
            $code === 131026 => 'número não recebe mensagens (não tem WhatsApp ou bloqueou)',
            $code === 131051 => 'tipo de mensagem não suportado',
            $code === 190 => 'token do system user inválido ou revogado — gere outro na Meta',
            $code === 100 && $subcode === 33 => 'phone_number_id não pertence a este token/app',
            $code === 132000, $code === 132001, $code === 132005, $code === 132007, $code === 132012, $code === 132015 => 'problema no template (nome, idioma, variáveis ou aprovação)',
            $code === 133010 => 'número não registrado na Cloud API',
            $code === 80007, $code === 130429 => 'limite de envio da Meta atingido (rate limit)',
            $code === 131056 => 'muitas mensagens para este destinatário em pouco tempo',
            default => '',
        };
    }

    /** Número no formato que a Meta espera: só dígitos, com DDI. */
    private function to(string $number): string
    {
        return (string) preg_replace('/\D/', '', $number);
    }

    /**
     * POST em /{phone_number_id}/messages. Devolve o wamid da mensagem, ou null.
     * O erro da Meta é logado com código/subcódigo — é o que diz se foi janela de 24h,
     * template não aprovado, token expirado ou número inválido.
     */
    private function send(array $payload, int $timeout = 20): ?string
    {
        $id = $this->account->phone_number_id;
        if (! $id || ! $this->account->access_token) {
            Evolution::log('cloud.envio.sem_credencial', ['account' => $this->account->id], 'error');
            Log::warning('wa-cloud: conta sem phone_number_id/token', ['account' => $this->account->id]);

            return null;
        }

        // Trilha do envio: quem, para quem, o quê. O corpo da mensagem não entra no log.
        $trilha = [
            'account' => $this->account->id,
            'phone_number_id' => $id,
            'para' => $payload['to'] ?? null,
            'tipo' => $payload['type'] ?? 'text',
        ];
        $t0 = microtime(true);

        try {
            $res = $this->http($timeout)->post("/{$id}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
            ] + $payload);

            $ms = (int) ((microtime(true) - $t0) * 1000);

            if (! $res->successful()) {
                $erro = $trilha + [
                    'ms' => $ms,
                    'status' => $res->status(),
                    'code' => $res->json('error.code'),
                    'subcode' => $res->json('error.error_subcode'),
                    'title' => $res->json('error.error_user_title'),
                    'detail' => mb_substr((string) $res->json('error.message'), 0, 300),
                    // 131047 = fora da janela de 24h; 131026 = número não recebe;
                    // 190 = token inválido/expirado; 132xxx = problema no template.
                    'dica' => self::dicaDoErro((int) $res->json('error.code'), (int) $res->json('error.error_subcode')),
                ];
                Evolution::log('cloud.envio.recusado', $erro, 'error');
                Log::warning('wa-cloud: envio recusado', $erro);

                return null;
            }

            $waId = (string) ($res->json('messages.0.id') ?? '');
            Evolution::log('cloud.envio.ok', $trilha + ['ms' => $ms, 'wa_id' => $waId]);

            return $waId;
        } catch (\Throwable $e) {
            Evolution::log('cloud.envio.excecao', $trilha + [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ], 'error');
            Log::warning('wa-cloud: exceção no envio', ['account' => $this->account->id, 'e' => $e->getMessage()]);

            return null;
        }
    }

    public function sendText(string $number, string $text, ?string $quotedId = null, string $quotedText = ''): ?string
    {
        $to = $this->to($number);
        if ($to === '' || trim($text) === '') {
            return null;
        }

        $payload = [
            'to' => $to,
            'type' => 'text',
            // preview_url deixa o WhatsApp montar o card do link (mesmo comportamento do app).
            'text' => ['body' => mb_substr($text, 0, 4096), 'preview_url' => true],
        ];
        if ($quotedId) {
            $payload['context'] = ['message_id' => $quotedId];
        }

        return $this->send($payload);
    }

    public function sendMedia(string $number, string $base64, string $mimetype, string $fileName, string $caption = '', string $mediatype = 'image'): ?string
    {
        $to = $this->to($number);
        if ($to === '' || $base64 === '') {
            return null;
        }

        $mediaId = $this->upload($base64, $mimetype, $fileName);
        if (! $mediaId) {
            return null;
        }

        // sticker não aceita caption; document é o único que leva filename.
        $node = ['id' => $mediaId];
        if ($caption !== '' && $mediatype !== 'sticker') {
            $node['caption'] = $caption;
        }
        if ($mediatype === 'document') {
            $node['filename'] = $fileName;
        }

        return $this->send(['to' => $to, 'type' => $mediatype, $mediatype => $node], timeout: 60);
    }

    public function sendAudio(string $number, string $base64): ?string
    {
        $to = $this->to($number);
        if ($to === '' || $base64 === '') {
            return null;
        }

        // Nota de voz de verdade exige OGG/Opus; outro formato chega como áudio anexado.
        $mediaId = $this->upload($base64, 'audio/ogg', 'audio.ogg');
        if (! $mediaId) {
            return null;
        }

        return $this->send(['to' => $to, 'type' => 'audio', 'audio' => ['id' => $mediaId]], timeout: 60);
    }

    public function sendReaction(string $number, string $waId, string $remoteJid, bool $fromMe, string $emoji): bool
    {
        $to = $this->to($number);
        if ($to === '' || $waId === '') {
            return false;
        }

        // Emoji vazio remove a reação (é o contrato da própria Meta).
        return $this->send([
            'to' => $to,
            'type' => 'reaction',
            'reaction' => ['message_id' => $waId, 'emoji' => $emoji],
        ]) !== null;
    }

    /**
     * Sobe um arquivo para a Meta e devolve o media id (válido por 30 dias).
     * O binário vai como multipart — o Graph não aceita base64 no corpo.
     */
    public function upload(string $base64, string $mimetype, string $fileName): ?string
    {
        $id = $this->account->phone_number_id;
        $bin = base64_decode($base64, true);
        if (! $id || $bin === false || $bin === '') {
            return null;
        }

        try {
            $res = Http::baseUrl('https://graph.facebook.com/'.$this->version())
                ->withToken((string) $this->account->access_token)
                ->timeout(120)
                ->attach('file', $bin, $fileName, ['Content-Type' => $mimetype])
                ->post("/{$id}/media", [
                    'messaging_product' => 'whatsapp',
                    'type' => $mimetype,
                ]);

            if (! $res->successful()) {
                Log::warning('wa-cloud: upload de mídia falhou', [
                    'account' => $this->account->id,
                    'status' => $res->status(),
                    'detail' => mb_substr((string) $res->json('error.message'), 0, 300),
                ]);

                return null;
            }

            return (string) ($res->json('id') ?? '') ?: null;
        } catch (\Throwable $e) {
            Log::warning('wa-cloud: exceção no upload', ['e' => $e->getMessage()]);

            return null;
        }
    }

    /** Resolve um media id na URL temporária de download (+ mime). */
    private function mediaUrl(string $mediaId): ?array
    {
        try {
            $res = $this->http(20)->get("/{$mediaId}");
            if (! $res->successful()) {
                return null;
            }
            $url = (string) ($res->json('url') ?? '');

            return $url !== '' ? ['url' => $url, 'mime' => (string) ($res->json('mime_type') ?? '')] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function mediaBase64(string $waId, ?string $mediaRef = null): ?string
    {
        if (! $mediaRef) {
            return null; // na Cloud API a mídia é endereçada pelo id dela, não pelo id da mensagem
        }
        $info = $this->mediaUrl($mediaRef);
        if (! $info) {
            return null;
        }

        try {
            // A URL do lookaside também exige o Bearer token.
            $res = Http::withToken((string) $this->account->access_token)->timeout(60)->get($info['url']);

            return $res->successful() ? base64_encode($res->body()) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function downloadMedia(string $waId, ?string $mediaRef, string $destPath): ?string
    {
        if (! $mediaRef) {
            return null;
        }
        $info = $this->mediaUrl($mediaRef);
        if (! $info) {
            return null;
        }

        try {
            // sink = grava direto no disco, sem carregar o vídeo inteiro em memória.
            $res = Http::withToken((string) $this->account->access_token)
                ->timeout(120)
                ->withOptions(['sink' => $destPath])
                ->get($info['url']);

            if (! $res->successful() || ! is_file($destPath) || filesize($destPath) === 0) {
                @unlink($destPath);

                return null;
            }

            return $info['mime'] ?: null;
        } catch (\Throwable $e) {
            @unlink($destPath);

            return null;
        }
    }

    /**
     * A Cloud API não tem "esse número existe no WhatsApp?". Responder true mantém o
     * fluxo das campanhas: o erro real volta no status `failed` do webhook.
     */
    public function isOnWhatsApp(string $number): bool
    {
        return $this->to($number) !== '';
    }

    /** Conexão é com a Meta: se o número responde no Graph, está no ar. */
    public function connectionState(): string
    {
        $id = $this->account->phone_number_id;
        if (! $id || ! $this->account->access_token) {
            return 'close';
        }
        try {
            $res = $this->http(12)->get("/{$id}", ['fields' => 'verified_name,quality_rating,platform_type']);

            return $res->successful() ? 'open' : 'close';
        } catch (\Throwable $e) {
            return 'close';
        }
    }

    /** Detalhes do número (nome exibido, qualidade, limite) para a tela de admin. */
    public function numberInfo(): array
    {
        $id = $this->account->phone_number_id;
        if (! $id) {
            return [];
        }
        try {
            $res = $this->http(12)->get("/{$id}", [
                'fields' => 'display_phone_number,verified_name,quality_rating,messaging_limit_tier,code_verification_status,platform_type',
            ]);

            return $res->successful() ? (array) $res->json() : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** A Cloud API não expõe as etiquetas do app Business. */
    public function findLabels(): array
    {
        return [];
    }

    public function handleLabel(string $number, string $labelId, string $action): bool
    {
        return false;
    }

    /** Janela de atendimento: 24h a contar da última mensagem recebida do cliente. */
    public function canSendFreeform(?int $lastInboundTs): bool
    {
        return $lastInboundTs !== null && (time() - $lastInboundTs) < 24 * 3600;
    }

    public function markRead(string $waId): bool
    {
        $id = $this->account->phone_number_id;
        if (! $id || $waId === '') {
            return false;
        }
        try {
            return $this->http(10)->post("/{$id}/messages", [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $waId,
            ])->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Templates da WABA (os que podem ser enviados fora da janela de 24h).
     * Só os APPROVED interessam para envio; a tela de admin mostra os demais com o status.
     */
    public function templates(): array
    {
        $waba = $this->account->waba_id;
        if (! $waba) {
            return [];
        }
        try {
            $res = $this->http(20)->get("/{$waba}/message_templates", [
                'limit' => 200,
                'fields' => 'name,status,category,language,components,quality_score',
            ]);

            return $res->successful() ? (array) ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('wa-cloud: falha ao listar templates', ['e' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Envia um template aprovado. `$bodyParams` preenche as variáveis {{1}}, {{2}}… do corpo
     * na ordem; `$headerParams` faz o mesmo no cabeçalho de texto, quando houver.
     */
    public function sendTemplate(string $number, string $name, string $language = 'pt_BR', array $bodyParams = [], array $headerParams = []): ?string
    {
        $to = $this->to($number);
        if ($to === '' || $name === '') {
            return null;
        }

        $components = [];
        if ($headerParams) {
            $components[] = [
                'type' => 'header',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($headerParams)),
            ];
        }
        if ($bodyParams) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($bodyParams)),
            ];
        }

        $template = ['name' => $name, 'language' => ['code' => $language]];
        if ($components) {
            $template['components'] = $components;
        }

        return $this->send(['to' => $to, 'type' => 'template', 'template' => $template]);
    }
}
