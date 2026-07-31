<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\WaAccount;
use App\Support\Channels\CloudChannel;
use App\Support\Channels\EvolutionChannel;
use App\Support\Channels\WaChannel;

/**
 * Porta de entrada para falar WhatsApp: resolve QUAL canal atende cada número.
 *
 *     Wa::for($conversation->account)->sendText($phone, $texto);
 *
 * Conta sem provider definido (todas as que existiam antes da API oficial) cai na
 * Evolution — o comportamento antigo continua idêntico enquanto o número não for
 * migrado para a Cloud API.
 */
class Wa
{
    public static function for(?WaAccount $account): WaChannel
    {
        if ($account && $account->isCloud()) {
            return new CloudChannel($account);
        }

        return new EvolutionChannel($account);
    }

    /** Canal do número que atende esta conversa. */
    public static function forConversation(Conversation $conversation): WaChannel
    {
        return self::for($conversation->account);
    }

    /** Canal do número principal da empresa atual. */
    public static function primary(): WaChannel
    {
        return self::for(WaAccount::primary());
    }
}
