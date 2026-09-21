<?php

namespace App\Support;

use App\Models\Conversation;

/**
 * Pedido de parada do cliente ("para de mandar", "não quero mais", "me tira daqui").
 *
 * Existe por causa da restrição de 14/08/2026: a Meta bloqueou a conta por "Sending
 * spam" depois de a IA insistir com quem já não respondia. Insistir com quem PEDIU
 * para parar é o caso mais grave dessa mesma família — e o único sinal explícito que
 * o cliente dá antes de bloquear ou denunciar o número.
 *
 * Desligar `auto_reply` corta as duas pontas de uma vez: a resposta automática
 * (AutoReplyTick) e a retomada ativa (NudgeTick, que só olha conversa com auto_reply
 * ligado). O humano continua livre para escrever na conversa — quem cala é o robô.
 */
class OptOut
{
    /**
     * Frases inteiras, não palavras soltas: "para" e "sai" aparecem o tempo todo em
     * conversa normal ("para minha empresa", "sai caro"), e desligar o atendimento de
     * um lead interessado por causa disso custaria mais caro que o falso negativo.
     */
    private const PADRAO = '/(\bpare\b|\bparem\b|par[ae] de (mandar|enviar|me mandar|me enviar|encher)|pode parar|deixa de (mandar|enviar)|nao quero (mais|receber|nada)|nao me (manda|mande|envie) (mais|nada|mensage|msg)|nao me (perturbe|procure|procura)|nao envie mais|nao tenho interesse|sem interesse|me (tira|remove|exclui|descadastra) (dessa|desta|dessas|da|do|de|daqui|dai)|descadastr|me deixa em paz|\bstop\b)/u';

    /** O texto do cliente é um pedido explícito de parar? */
    public static function pediuParar(?string $texto): bool
    {
        $t = trim(mb_strtolower((string) $texto));
        if ($t === '') {
            return false;
        }

        // Sem acento: o cliente escreve "não" e "nao" com a mesma intenção.
        $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return (bool) preg_match(self::PADRAO, $t);
    }

    /**
     * Aplica o pedido na conversa. Devolve true se desligou algo agora (para o log não
     * repetir a cada mensagem seguinte do mesmo cliente).
     */
    public static function aplicar(Conversation $conv, ?string $texto): bool
    {
        if (! $conv->auto_reply || ! self::pediuParar($texto)) {
            return false;
        }

        $conv->auto_reply = false;
        $conv->auto_reply_due_at = null;
        $conv->nudge_count = 0;
        $conv->nudge_last_at = null;

        Evolution::log('opt_out.cliente_pediu_parar', [
            'conversation_id' => $conv->id,
            'phone' => $conv->phone,
            'texto' => mb_substr((string) $texto, 0, 120),
        ], 'warning');

        return true;
    }
}
