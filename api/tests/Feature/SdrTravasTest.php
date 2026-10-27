<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\SdrAgent;
use App\Services\Sdr\Sdr;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As travas anti-spam do agente SDR (Sdr::bloqueio). O agente decide quem procurar; o que
 * ele NÃO pode é insistir com quem não responde — foi isso que travou o número na Meta em
 * 14/08/2026. Cada teste tira uma condição do lead "liberado" e confere a recusa.
 */
class SdrTravasTest extends TestCase
{
    use RefreshDatabase;

    private Company $co;

    protected function setUp(): void
    {
        parent::setUp();
        // Terça, 10:00 — dentro do horário padrão (seg–sex, 9–19).
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'America/Sao_Paulo'));
        $this->co = Company::create(['name' => 'Empresa S', 'slug' => 'empresa-s', 'is_active' => true]);
        app(Tenancy::class)->set($this->co->id);
    }

    protected function tearDown(): void
    {
        app(Tenancy::class)->forget();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cfg(array $extra = []): SdrAgent
    {
        $cfg = SdrAgent::atual();
        $cfg->update($extra + ['enabled' => true]);

        return $cfg->fresh();
    }

    /** Lead qualificado que falou ontem e recebeu a nossa resposta — o caso liberado. */
    private function lead(array $extra = []): Conversation
    {
        $c = Conversation::create($extra + [
            'slug' => 'lead-'.uniqid(), 'name' => 'Lead Teste', 'initials' => 'L', 'color' => '#8696a0',
            'phone' => '5541999990000', 'auto_reply' => true, 'qualified' => true, 'stage' => 'novo',
            'last_message_at' => now()->subDay(),
        ]);
        $this->msg($c, false, now()->subDay()->subMinutes(5));
        $this->msg($c, true, now()->subDay());

        return $c;
    }

    private function msg(Conversation $c, bool $nossa, Carbon $quando, ?string $meta = null): void
    {
        $c->messages()->create([
            'type' => 'text', 'is_out' => $nossa, 'text' => $nossa ? 'nós' : 'lead',
            'time' => $quando->format('H:i'), 'ts' => $quando->timestamp, 'meta' => $meta,
            'position' => ((int) $c->messages()->max('position')) + 1,
        ]);
    }

    public function test_lead_qualificado_parado_pode_receber(): void
    {
        $this->assertNull((new Sdr($this->cfg()))->bloqueio($this->lead()));
    }

    public function test_sequencia_sem_resposta_trava_no_maximo(): void
    {
        $c = $this->lead();
        $this->msg($c, true, now()->subDays(3)->addMinutes(1), 'sdr'); // antes da última fala dele: não conta
        $this->msg($c, true, now()->subHours(23), 'sdr');
        $this->msg($c, true, now()->subHours(21), 'sdr');

        $b = (new Sdr($this->cfg(['max_unanswered' => 2])))->bloqueio($c);
        $this->assertStringContainsString('sem resposta', (string) $b);
    }

    public function test_intervalo_minimo_entre_mensagens_sem_resposta(): void
    {
        $c = $this->lead();
        $this->msg($c, true, now()->subHours(5), 'sdr');

        $b = (new Sdr($this->cfg(['min_hours_between' => 20])))->bloqueio($c);
        $this->assertStringContainsString('mínimo 20h', (string) $b);
    }

    public function test_resposta_do_lead_zera_a_sequencia(): void
    {
        $c = $this->lead();
        $this->msg($c, true, now()->subHours(6), 'sdr');
        $this->msg($c, true, now()->subHours(5), 'sdr');
        $this->msg($c, false, now()->subHours(2));

        $this->assertNull((new Sdr($this->cfg(['max_unanswered' => 2])))->bloqueio($c));
    }

    public function test_humano_no_comando_reprovado_e_compareceu_ficam_fora(): void
    {
        $sdr = new Sdr($this->cfg());

        $this->assertStringContainsString('humano', (string) $sdr->bloqueio($this->lead(['ai_paused_until' => now()->addHour()])));
        $this->assertStringContainsString('reprovado', (string) $sdr->bloqueio($this->lead(['qualified' => false])));
        $this->assertStringContainsString('parar', (string) $sdr->bloqueio($this->lead(['auto_reply' => false])));

        $foi = $this->lead();
        Meeting::create(['conversation_id' => $foi->id, 'title' => 'Call', 'phone' => $foi->phone,
            'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour(), 'attended' => true]);
        $this->assertStringContainsString('closer', (string) $sdr->bloqueio($foi));

        $ids = $sdr->escopo()->pluck('id');
        $this->assertNotContains($foi->id, $ids);
    }

    public function test_fora_do_horario_nao_envia(): void
    {
        $c = $this->lead();
        Carbon::setTestNow(Carbon::parse('2026-10-27 21:00:00', 'America/Sao_Paulo'));

        $this->assertStringContainsString('horário', (string) (new Sdr($this->cfg()))->bloqueio($c));
    }

    public function test_teto_do_dia(): void
    {
        $outro = $this->lead();
        $this->msg($outro, true, now()->subMinutes(30), 'sdr');

        $this->assertStringContainsString('teto do dia', (string) (new Sdr($this->cfg(['daily_cap' => 1])))->bloqueio($this->lead()));
    }

    public function test_desligado_so_simula(): void
    {
        $c = $this->lead();
        $cfg = $this->cfg(['enabled' => false]);

        $this->assertStringContainsString('desligado', (string) (new Sdr($cfg))->bloqueio($c));
        $this->assertNull((new Sdr($cfg, simular: true))->bloqueio($c));
        $this->assertStringStartsWith('SIMULAÇÃO', (new Sdr($cfg, simular: true))->enviar($c->id, 'Oi! Conseguiu ver os horários?', 'teste'));
        $this->assertSame(0, $c->messages()->where('meta', 'sdr')->count());
    }
}
