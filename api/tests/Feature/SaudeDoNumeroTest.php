<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use App\Models\WaAccount;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * O vigia dos números (wa:health-tick).
 *
 * Existe porque o número oficial ficou mudo de 19 a 21/09/2026 e o sistema não avisou:
 * o painel de saúde só atualizava quando alguém abria a tela.
 */
class SaudeDoNumeroTest extends TestCase
{
    use RefreshDatabase;

    private function contaComMovimento(int $porDia, int $horasDeSilencio): WaAccount
    {
        $co = Company::create(['name' => 'Saude', 'slug' => 'saude-'.Str::random(5), 'is_active' => true]);

        return app(Tenancy::class)->run($co->id, function () use ($co, $porDia, $horasDeSilencio) {
            $conta = WaAccount::create([
                'name' => 'Oficial', 'provider' => 'cloud', 'phone' => '551199999999',
                'role' => 'primary', 'is_active' => true, 'company_id' => $co->id,
            ]);
            $conv = Conversation::create([
                'name' => 'Lead', 'slug' => 'wa-551198888888', 'initials' => 'L',
                'color' => '#888', 'wa_account_id' => $conta->id, 'company_id' => $co->id,
            ]);

            // Movimento dos últimos 7 dias, tudo ANTES da janela de silêncio.
            for ($d = 1; $d <= 7; $d++) {
                for ($i = 0; $i < $porDia; $i++) {
                    Message::create([
                        'conversation_id' => $conv->id, 'is_out' => false, 'type' => 'text',
                        'text' => 'oi', 'ts' => now()->subDays($d)->timestamp, 'company_id' => $co->id,
                    ]);
                }
            }
            // E nada nas últimas N horas.
            if ($horasDeSilencio < 6) {
                Message::create([
                    'conversation_id' => $conv->id, 'is_out' => false, 'type' => 'text',
                    'text' => 'recente', 'ts' => now()->subHours($horasDeSilencio)->timestamp,
                    'company_id' => $co->id,
                ]);
            }

            return $conta;
        });
    }

    public function test_numero_que_costuma_receber_e_ficou_mudo_vira_tarefa(): void
    {
        // Terça-feira, 14h: horário em que o silêncio realmente significa alguma coisa.
        Carbon::setTestNow(Carbon::parse('2026-09-22 14:00:00'));
        $conta = $this->contaComMovimento(porDia: 10, horasDeSilencio: 48);

        $this->artisan('wa:health-tick')->assertSuccessful();

        $tarefa = Task::where('title', 'like', '%Número com problema%')->first();
        $this->assertNotNull($tarefa, 'devia ter aberto tarefa para o número mudo');
        $this->assertSame('alta', $tarefa->priority);
        $this->assertStringContainsString('não recebe mensagem', (string) $tarefa->description);
        $this->assertNotNull($conta->fresh()->health_checked_at);
    }

    public function test_nao_abre_uma_tarefa_por_rodada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 14:00:00'));
        $this->contaComMovimento(porDia: 10, horasDeSilencio: 48);

        $this->artisan('wa:health-tick');
        $this->artisan('wa:health-tick');
        $this->artisan('wa:health-tick');

        $this->assertSame(1, Task::where('title', 'like', '%Número com problema%')->count());
    }

    public function test_numero_com_movimento_normal_nao_alarma(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 14:00:00'));
        $this->contaComMovimento(porDia: 10, horasDeSilencio: 1);

        $this->artisan('wa:health-tick');

        $this->assertSame(0, Task::where('title', 'like', '%Número com problema%')->count());
    }

    public function test_de_madrugada_o_silencio_nao_e_defeito(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 03:00:00'));
        $this->contaComMovimento(porDia: 10, horasDeSilencio: 48);

        $this->artisan('wa:health-tick');

        $this->assertSame(0, Task::where('title', 'like', '%Número com problema%')->count());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
