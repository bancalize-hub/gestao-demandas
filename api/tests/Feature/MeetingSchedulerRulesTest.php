<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Services\GoogleCalendarService;
use App\Services\MeetingScheduler;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regra de ouro do agendamento: no máximo UM agendamento ativo por contato.
 * Quem decide é o servidor — a IA só sugere. Estes testes cobrem essa decisão
 * (resolveAction) e a validação do dia/horário (resolveStart), sem tocar Google/IA.
 */
class MeetingSchedulerRulesTest extends TestCase
{
    private function scheduler(): MeetingScheduler
    {
        return new MeetingScheduler($this->createMock(GoogleCalendarService::class));
    }

    private function action(array $data, ?Meeting $active): string
    {
        $m = new ReflectionMethod(MeetingScheduler::class, 'resolveAction');
        $m->setAccessible(true);

        return $m->invoke($this->scheduler(), $data, $active);
    }

    private function ativo(): Meeting
    {
        return new Meeting(['starts_at' => Carbon::now()->addDays(2)->setTime(14, 0), 'title' => 'Reunião']);
    }

    public function test_marcar_vira_remarcar_quando_ja_existe_agendamento_ativo(): void
    {
        // Nunca dois agendamentos ativos: mesmo se a IA pedir "marcar", o servidor remarca.
        $this->assertSame('remarcar', $this->action(['action' => 'marcar'], $this->ativo()));
        $this->assertSame('remarcar', $this->action(['book' => true], $this->ativo()));
    }

    public function test_sem_agendamento_ativo_remarcar_vira_marcar_e_cancelar_vira_nada(): void
    {
        $this->assertSame('marcar', $this->action(['action' => 'remarcar'], null));
        $this->assertSame('nada', $this->action(['action' => 'cancelar'], null));
    }

    public function test_acoes_reconhecidas_e_fallback_do_campo_antigo(): void
    {
        $this->assertSame('cancelar', $this->action(['action' => 'cancelar'], $this->ativo()));
        $this->assertSame('perguntar', $this->action(['action' => 'perguntar'], $this->ativo()));
        $this->assertSame('nada', $this->action(['action' => 'nada'], $this->ativo()));
        // Resposta antiga (só "book"), sem reunião ativa.
        $this->assertSame('marcar', $this->action(['book' => true], null));
        $this->assertSame('nada', $this->action(['book' => false], null));
        // Lixo na "action" cai no "book".
        $this->assertSame('nada', $this->action(['action' => 'xpto'], null));
    }

    public function test_resolve_start_so_aceita_dia_da_lista_e_horario_valido(): void
    {
        $m = new ReflectionMethod(MeetingScheduler::class, 'resolveStart');
        $m->setAccessible(true);
        $tz = 'America/Sao_Paulo';
        $dias = ['2026-08-10', '2026-08-11'];

        $ok = $m->invoke($this->scheduler(), ['book_day' => '2026-08-10', 'book_time' => '14:30'], $dias, $tz);
        $this->assertInstanceOf(Carbon::class, $ok);
        $this->assertSame('2026-08-10 14:30', $ok->format('Y-m-d H:i'));

        // Dia fora da janela oferecida, horário inválido, madrugada e campos vazios → null.
        $this->assertNull($m->invoke($this->scheduler(), ['book_day' => '2026-09-01', 'book_time' => '14:30'], $dias, $tz));
        $this->assertNull($m->invoke($this->scheduler(), ['book_day' => '2026-08-10', 'book_time' => '25:00'], $dias, $tz));
        $this->assertNull($m->invoke($this->scheduler(), ['book_day' => '2026-08-10', 'book_time' => '03:00'], $dias, $tz));
        $this->assertNull($m->invoke($this->scheduler(), [], $dias, $tz));
    }
}
