<?php

namespace App\Support;

use App\Models\Company;
use Carbon\CarbonInterface;

/**
 * Horário de atendimento da empresa — a régua única de "quando a IA pode tomar a
 * iniciativa": retomada ativa, oferta de horário de reunião e o padrão de janela de
 * campanha nova leem daqui.
 *
 * O que NÃO passa por aqui, de propósito: a resposta imediata a quem escreve
 * (AutoReplyTick). Lead que manda mensagem às 23h é conversa viva — segurar a resposta
 * até as 9h transformaria atendimento em fila, e esse é justamente o diferencial que a
 * operação não quer perder.
 */
class Attendance
{
    /**
     * Vale quando a empresa nunca configurou nada: seg–sex, 09:00–19:00.
     *
     * Dias em ISO (1=segunda … 7=domingo). O horário espelha o que a retomada já usava
     * (9–19). Sábado fica de fora do padrão — reunião ofertada no sábado sem ninguém
     * para atender é pior que uma retomada a menos; quem atende sábado marca na tela.
     */
    public const PADRAO = ['days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '19:00'];

    /**
     * A configuração da empresa, saneada. Entrada podre (dia fora de 1..7, hora sem
     * formato, start >= end) cai no PADRÃO em vez de propagar — uma janela inválida
     * silenciosa pararia a retomada da empresa inteira sem ninguém perceber.
     *
     * @return array{days: list<int>, start: string, end: string}
     */
    public static function da(?Company $company): array
    {
        $cfg = $company?->attendance;
        if (! is_array($cfg)) {
            return self::PADRAO;
        }

        $days = array_values(array_unique(array_filter(
            array_map('intval', (array) ($cfg['days'] ?? [])),
            fn (int $d) => $d >= 1 && $d <= 7,
        )));
        sort($days);

        $hora = fn ($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
        $start = $hora($cfg['start'] ?? null);
        $end = $hora($cfg['end'] ?? null);

        if (! $days || ! $start || ! $end || $start >= $end) {
            return self::PADRAO;
        }

        return ['days' => $days, 'start' => $start, 'end' => $end];
    }

    /** Estamos dentro do horário de atendimento desta empresa? */
    public static function dentro(?Company $company, ?CarbonInterface $agora = null): bool
    {
        $cfg = self::da($company);
        $agora ??= now();

        if (! in_array($agora->isoWeekday(), $cfg['days'], true)) {
            return false;
        }

        $hm = $agora->format('H:i');

        return $hm >= $cfg['start'] && $hm < $cfg['end'];
    }

    /**
     * Início e fim em horas inteiras — o formato que o gerador de horários de reunião
     * consome. 08:30 vira 8 (arredonda a favor de oferecer): o minuto fino não vale a
     * cirurgia no gerador, que trabalha em passos de 30min dentro de horas cheias.
     *
     * @return array{0: int, 1: int}
     */
    public static function horas(?Company $company): array
    {
        $cfg = self::da($company);

        return [(int) substr($cfg['start'], 0, 2), (int) substr($cfg['end'], 0, 2)];
    }

    /**
     * Dias ISO em que a empresa atende — o gerador de reunião usa para pular dias, no
     * lugar do antigo "pula fim de semana" fixo.
     *
     * @return list<int>
     */
    public static function dias(?Company $company): array
    {
        return self::da($company)['days'];
    }
}
