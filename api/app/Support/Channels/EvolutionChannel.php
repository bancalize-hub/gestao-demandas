<?php

namespace App\Support\Channels;

use App\Models\WaAccount;
use App\Support\Evolution;

/**
 * Canal Evolution API (Baileys, não-oficial) — comportamento histórico do CRM,
 * apenas embrulhado na interface. Toda a lógica continua em {@see Evolution}.
 */
class EvolutionChannel implements WaChannel
{
    public function __construct(private ?WaAccount $account = null) {}

    private function instance(): ?string
    {
        return $this->account?->instance;
    }

    public function sendText(string $number, string $text, ?string $quotedId = null, string $quotedText = ''): ?string
    {
        return Evolution::sendText($number, $text, $quotedId, $quotedText, instance: $this->instance());
    }

    public function sendMedia(string $number, string $base64, string $mimetype, string $fileName, string $caption = '', string $mediatype = 'image'): ?string
    {
        return Evolution::sendMedia($number, $base64, $mimetype, $fileName, $caption, $mediatype, instance: $this->instance());
    }

    public function sendAudio(string $number, string $base64): ?string
    {
        return Evolution::sendAudio($number, $base64, instance: $this->instance());
    }

    public function sendReaction(string $number, string $waId, string $remoteJid, bool $fromMe, string $emoji): bool
    {
        return Evolution::sendReaction($number, $waId, $remoteJid, $fromMe, $emoji, instance: $this->instance());
    }

    public function mediaBase64(string $waId, ?string $mediaRef = null): ?string
    {
        return Evolution::mediaBase64($waId, instance: $this->instance());
    }

    /**
     * A Evolution devolve a mídia como base64 dentro de um JSON; quem serve o arquivo
     * (WhatsAppController::media) já faz o streaming desse JSON para o disco. Aqui o
     * caminho simples basta — é usado só pelos canais que baixam binário direto.
     */
    public function downloadMedia(string $waId, ?string $mediaRef, string $destPath): ?string
    {
        $b64 = $this->mediaBase64($waId);
        if (! $b64) {
            return null;
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || file_put_contents($destPath, $bin) === false) {
            return null;
        }

        return null; // mime desconhecido aqui; o chamador cai no messages.meta
    }

    public function isOnWhatsApp(string $number): bool
    {
        return Evolution::isOnWhatsApp($number, instance: $this->instance());
    }

    public function connectionState(): string
    {
        return Evolution::connectionState(instance: $this->instance());
    }

    public function findLabels(): array
    {
        return Evolution::findLabels(instance: $this->instance());
    }

    public function handleLabel(string $number, string $labelId, string $action): bool
    {
        return Evolution::handleLabel($number, $labelId, $action, instance: $this->instance());
    }

    /** Baileys conversa como um celular: não existe janela de 24h nem template. */
    public function canSendFreeform(?int $lastInboundTs): bool
    {
        return true;
    }

    /** A Evolution marca como lido por chat, não por mensagem; não usamos aqui. */
    public function markRead(string $waId): bool
    {
        return false;
    }
}
