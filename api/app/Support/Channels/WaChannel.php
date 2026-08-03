<?php

namespace App\Support\Channels;

use App\Support\Wa;

/**
 * Um canal de WhatsApp. Duas implementações:
 *
 *  - {@see EvolutionChannel}: Evolution API (Baileys, NÃO-oficial) — o canal histórico do CRM.
 *  - {@see CloudChannel}: Cloud API da Meta (OFICIAL).
 *
 * O resto da aplicação fala sempre com esta interface (via {@see Wa::for()}),
 * então trocar o canal de um número é mudar uma coluna, não mudar o código.
 *
 * Convenção herdada da Evolution e mantida aqui: os métodos de envio devolvem o
 * `wa_id` da mensagem (string) em caso de sucesso e `null` em caso de falha — nunca
 * lançam. O wa_id é o que casa o eco do webhook com a mensagem já gravada.
 */
interface WaChannel
{
    /** Envia texto. `$quotedId` responde nativamente a outra mensagem (aparece citada no cliente). */
    public function sendText(string $number, string $text, ?string $quotedId = null, string $quotedText = ''): ?string;

    /** Envia mídia. `$base64` é o conteúdo puro (sem prefixo data:). `$mediatype` ∈ image|video|document. */
    public function sendMedia(string $number, string $base64, string $mimetype, string $fileName, string $caption = '', string $mediatype = 'image'): ?string;

    /** Envia áudio como nota de voz (PTT). */
    public function sendAudio(string $number, string $base64): ?string;

    /** Reage a uma mensagem; emoji vazio remove a reação. */
    public function sendReaction(string $number, string $waId, string $remoteJid, bool $fromMe, string $emoji): bool;

    /**
     * Baixa a mídia de uma mensagem e devolve o base64 cru.
     * `$mediaRef` é o id de mídia próprio do canal (Cloud API); a Evolution resolve pelo wa_id.
     */
    public function mediaBase64(string $waId, ?string $mediaRef = null): ?string;

    /**
     * Baixa a mídia direto para um arquivo (memória O(1) — usado para servir vídeo/áudio grandes).
     * Devolve o mime-type, ou null se não houver mídia.
     */
    public function downloadMedia(string $waId, ?string $mediaRef, string $destPath): ?string;

    /** O número existe no WhatsApp? (Canais sem essa checagem respondem true.) */
    public function isOnWhatsApp(string $number): bool;

    /** Estado da conexão: open | connecting | close. */
    public function connectionState(): string;

    /** Etiquetas do WhatsApp Business. A Cloud API não expõe etiquetas → lista vazia. */
    public function findLabels(): array;

    /** Aplica/remove etiqueta num chat. Sem suporte no canal → false. */
    public function handleLabel(string $number, string $labelId, string $action): bool;

    /**
     * Pode enviar texto livre para este número agora?
     *
     * Na Cloud API só dentro da janela de 24h desde a última mensagem do cliente;
     * fora dela é obrigatório um template aprovado. Na Evolution não há janela.
     */
    public function canSendFreeform(?int $lastInboundTs): bool;

    /**
     * Envia um template aprovado — o único caminho fora da janela de 24h.
     * Devolve o id da mensagem no WhatsApp, ou null se o canal não tem templates
     * (Evolution) ou a Meta recusou.
     */
    public function sendTemplate(string $number, string $name, string $language = 'pt_BR', array $bodyParams = [], array $headerParams = []): ?string;

    /** Marca as mensagens do cliente como lidas no WhatsApp dele (best-effort). */
    public function markRead(string $waId): bool;
}
