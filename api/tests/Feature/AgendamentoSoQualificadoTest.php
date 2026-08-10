<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\MeetingScheduler;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A IA só marca reunião com lead QUALIFICADO (empresa com triagem ativa): sem veredito
 * ou reprovado, o agendador devolve "nada" com a explicação, sem tocar na agenda.
 * Reprovado não marca em empresa nenhuma; a exigência do veredito positivo é só de quem
 * tem critério de triagem preenchido. Ver MeetingScheduler::decideAndBook.
 */
class AgendamentoSoQualificadoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sentinela: se o agendador PASSOU da trava, a primeira coisa que ele faz depois é
     * consultar a agenda — o mock estoura com esta mensagem e o teste sabe que a porta
     * abriu, sem depender de Google nem da IA de verdade.
     */
    private const PASSOU_DA_TRAVA = 'passou-da-trava';

    private function scheduler(): MeetingScheduler
    {
        $google = $this->createMock(GoogleCalendarService::class);
        $google->method('freeSlotsForHosts')->willThrowException(new \RuntimeException(self::PASSOU_DA_TRAVA));

        return new MeetingScheduler($google);
    }

    private function empresa(bool $triagem): array
    {
        $co = Company::create([
            'name' => 'Empresa Q', 'slug' => 'empresa-q-'.($triagem ? 'com' : 'sem'), 'is_active' => true,
            'qualify_enabled' => $triagem,
            'qualify_criteria' => $triagem ? 'Qualificado: tem operação própria. Desqualificado: quer crédito pessoal.' : null,
        ]);
        $user = User::create(['name' => 'Dono', 'email' => "q-{$co->slug}@t.com", 'password' => bcrypt('x'), 'company_id' => $co->id]);

        return [$co, $user];
    }

    private function conversa(Company $co, ?bool $qualified): Conversation
    {
        return app(Tenancy::class)->run($co->id, fn () => Conversation::create([
            'slug' => 'lead-'.uniqid(), 'name' => 'Lead', 'initials' => 'L', 'color' => '#8696a0',
            'qualified' => $qualified, 'stage' => 'novo',
        ]));
    }

    public function test_sem_triagem_concluida_nao_marca(): void
    {
        [$co, $user] = $this->empresa(triagem: true);
        $conv = $this->conversa($co, qualified: null);

        $res = $this->scheduler()->decideAndBook($user, $conv);

        $this->assertSame('nada', $res['action']);
        $this->assertFalse($res['scheduled']);
        $this->assertNull($res['message']);
        $this->assertStringContainsString('sem triagem', $res['note']);
    }

    public function test_reprovado_nao_marca_mesmo_sem_triagem_ativa(): void
    {
        [$co, $user] = $this->empresa(triagem: false);
        $conv = $this->conversa($co, qualified: false);

        $res = $this->scheduler()->decideAndBook($user, $conv);

        $this->assertSame('nada', $res['action']);
        $this->assertStringContainsString('reprovado', $res['note']);
    }

    public function test_qualificado_passa_da_trava(): void
    {
        [$co, $user] = $this->empresa(triagem: true);
        $conv = $this->conversa($co, qualified: true);

        $this->expectExceptionMessage(self::PASSOU_DA_TRAVA);
        $this->scheduler()->decideAndBook($user, $conv);
    }

    public function test_empresa_sem_triagem_nao_exige_veredito(): void
    {
        [$co, $user] = $this->empresa(triagem: false);
        $conv = $this->conversa($co, qualified: null);

        $this->expectExceptionMessage(self::PASSOU_DA_TRAVA);
        $this->scheduler()->decideAndBook($user, $conv);
    }

    public function test_lead_com_reuniao_ativa_passa_para_poder_remarcar_ou_cancelar(): void
    {
        [$co, $user] = $this->empresa(triagem: true);
        $conv = $this->conversa($co, qualified: null);
        app(Tenancy::class)->run($co->id, fn () => Meeting::create([
            'conversation_id' => $conv->id, 'user_id' => $user->id,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
            'title' => 'Reunião — teste',
        ]));

        $this->expectExceptionMessage(self::PASSOU_DA_TRAVA);
        $this->scheduler()->decideAndBook($user, $conv);
    }
}
