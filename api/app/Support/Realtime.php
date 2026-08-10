<?php

namespace App\Support;

use App\Events\MessageCreated;
use App\Events\MessagePatched;
use App\Models\Conversation;
use App\Models\Message;

/**
 * Push de tempo real do chat: em vez de avisar "algo mudou" e o front re-baixar
 * a lista inteira + a thread inteira, os eventos carregam o PRÓPRIO dado (a
 * mensagem nova / o campo alterado) e o front aplica um patch incremental.
 *
 * Tudo best-effort: se o Reverb estiver fora, nada aqui pode derrubar o fluxo
 * (webhook, envio, ticks do agendador).
 */
class Realtime
{
    /** Mensagem nova → envia a mensagem + a linha atualizada da conversa (lista). */
    public static function messageCreated(Message $message): void
    {
        if (Conversation::$muteBroadcast) {
            return;
        }
        try {
            // Inclui desqualificado de propósito: quem esconde é o front. Sem isto, quem
            // abriu um lead reprovado para tentar recuperá-lo pararia de ver as respostas
            // dele chegando — e a IA, que segue conversando, ficaria trabalhando às cegas.
            $conv = Conversation::listQuery(true)->whereKey($message->conversation_id)->first();
            if (! $conv) {
                return;
            }
            broadcast(new MessageCreated($conv, $message))->toOthers();
        } catch (\Throwable $e) {
        }
    }

    /** Campos de uma mensagem existente mudaram (status/reaction/transcript/removida). */
    public static function messagePatched(Message $message, array $patch): void
    {
        if (Conversation::$muteBroadcast) {
            return;
        }
        try {
            $conv = $message->conversation()->first(['id', 'slug', 'company_id']);
            if (! $conv) {
                return;
            }
            broadcast(new MessagePatched($conv, $message, $patch))->toOthers();
        } catch (\Throwable $e) {
        }
    }
}
