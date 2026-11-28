<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\MarketingAutopilot;
use App\Models\Meeting;
use App\Models\User;
use App\Models\WaAccount;
use App\Services\AlarmeDaSala;
use App\Services\GoogleCalendarService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Alarme da sala (meetings:noshow-tick): o lead que espera na sala de espera do Meet e
 * ninguém nosso entra tem de virar WhatsApp para a equipe na hora.
 *
 * Os casos vêm do que aconteceu de verdade (set a nov/2026): com ninguém nosso na sala, a
 * API do Meet não devolve participante nenhum, e a versão antiga concluía "não é falta
 * nossa"; com o anfitrião sem Google, ela pulava a reunião calada.
 */
class AlarmeDaSalaTest extends TestCase
{
    use RefreshDatabase;

    private const FONE_AVISO = '5541900000001';

    private Company $co;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.evolution.url' => 'http://evo.test', 'services.crm.team_display_names' => ['Bancalize', 'Maysa Lima']]);
        Http::fake(['*' => Http::response(['key' => ['id' => 'x']])]);
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:35:00', 'America/Sao_Paulo'));

        $this->co = Company::create(['name' => 'Bancalize', 'slug' => 'b-'.Str::random(5), 'is_active' => true]);
        app(Tenancy::class)->set($this->co->id);
        WaAccount::create(['name' => 'Vertice', 'instance' => 'vertice-teste', 'phone' => '5541900000009',
            'role' => 'outreach', 'provider' => 'evolution', 'is_active' => true, 'avatars_only' => true]);
        MarketingAutopilot::create(['enabled' => false, 'alert_phone' => self::FONE_AVISO]);

        $this->host = User::create(['name' => 'Administrador', 'email' => 'adm-'.Str::random(4).'@teste.dev',
            'password' => bcrypt('x'), 'company_id' => $this->co->id]);
        $this->host->forceFill(['google_refresh_token' => 'rt', 'is_host' => false, 'agenda_ativa' => false])->save();
        app(Tenancy::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reuniao(string $inicio = '2026-11-30 10:30:00', ?User $host = null, bool $comLead = true): Meeting
    {
        return app(Tenancy::class)->run($this->co->id, function () use ($inicio, $host, $comLead) {
            $conv = $comLead ? Conversation::create([
                'slug' => 'lead-'.uniqid(), 'name' => 'Viny', 'initials' => 'V', 'color' => '#8696a0',
                'phone' => '5575900000000', 'qualified' => true, 'stage' => 'proposta',
            ]) : null;

            return Meeting::create([
                'conversation_id' => $conv?->id, 'user_id' => ($host ?? $this->host)->id, 'phone' => $conv?->phone,
                'title' => 'Reunião — Viny', 'meet_link' => 'https://meet.google.com/abc-defg-hij',
                'starts_at' => Carbon::parse($inicio), 'ends_at' => Carbon::parse($inicio)->addHour(),
            ]);
        });
    }

    private function leadEscreve(Meeting $m, string $texto, string $quando): void
    {
        app(Tenancy::class)->run($this->co->id, fn () => $m->conversation->messages()->create([
            'type' => 'text', 'is_out' => false, 'text' => $texto,
            'time' => Carbon::parse($quando)->format('H:i'), 'ts' => Carbon::parse($quando)->timestamp, 'position' => 1,
        ]));
    }

    /** O Meet devolve estes participantes (vazio = ninguém admitido na sala). */
    private function salaCom(array $nomes): void
    {
        $this->mock(GoogleCalendarService::class, function ($mock) use ($nomes) {
            $mock->shouldReceive('conferenceAttendance')->andReturn([
                'found' => $nomes !== [],
                'participants' => array_map(fn ($n) => ['name' => $n, 'minutes' => 3, 'bot' => false], $nomes),
            ]);
        });
    }

    /** @return array<int, array{number: string, text: string}> */
    private function enviados(): array
    {
        return Http::recorded()
            ->filter(fn ($par) => str_contains($par[0]->url(), '/message/sendText'))
            ->map(fn ($par) => ['number' => $par[0]['number'], 'text' => $par[0]['text']])
            ->values()->all();
    }

    public function test_reconhece_as_mensagens_reais_de_quem_esperou(): void
    {
        $reais = [
            'To aguardando me aceitarem', 'Aguardando na sala', 'Eu estou aguardando aceitarem',
            'Estou aguardando aceitar', 'entrei ninguém aceitou', 'entrei na reunião, mas ninguém me adicionou',
            'Ninguém entrou na sala', 'Ja solicitei para entrar', 'Estou pedindo para entrar e ninguém aceitou',
            'Estou aguardando ser adicionada', 'Pedi prá entrar e nada',
            'Eu entrei na calça fiquei por 5 minutos e ninguém apareceu', 'Bom dia cadê vocês',
            'ja pedi para entrar', 'Estou aguardando', 'faz horas que peco', 'kd vcs ??', 'Boa noite pode entrar',
            'Solicitado',
        ];
        foreach ($reais as $t) {
            $this->assertTrue(AlarmeDaSala::pareceEsperando($t), "devia alarmar: {$t}");
        }

        foreach (['Ok', 'Obrigado, até amanhã', 'Qual o valor da taxa?', 'pdf nao aceita', 'Bom dia', ''] as $t) {
            $this->assertFalse(AlarmeDaSala::pareceEsperando($t), "não devia alarmar: {$t}");
        }
    }

    public function test_lead_escreve_que_esta_esperando_vira_whatsapp_na_hora_e_uma_vez_so(): void
    {
        $this->salaCom(['Bancalize']); // mesmo com alguém nosso lá: alguém tem de aceitar o lead
        $m = $this->reuniao();
        $this->leadEscreve($m, 'kd vcs ??', '2026-11-30 10:34:00');

        $this->artisan('meetings:noshow-tick')->assertSuccessful();
        $this->artisan('meetings:noshow-tick')->assertSuccessful();

        $enviados = $this->enviados();
        $this->assertCount(1, $enviados);
        $this->assertSame(self::FONE_AVISO, $enviados[0]['number']);
        $this->assertStringContainsString('Lead esperando na sala', $enviados[0]['text']);
        $this->assertStringContainsString('kd vcs ??', $enviados[0]['text']);
        $this->assertStringContainsString('meet.google.com/abc-defg-hij', $enviados[0]['text']);
    }

    public function test_lead_na_sala_de_espera_e_ninguem_nosso_alarma_mesmo_sem_participante(): void
    {
        // A sala de espera não aparece na API: sem ninguém nosso, a sala nem abre.
        $this->salaCom([]);
        $this->reuniao();

        $this->artisan('meetings:noshow-tick')->assertSuccessful();

        $enviados = $this->enviados();
        $this->assertCount(1, $enviados);
        $this->assertStringContainsString('Ninguém da equipe na sala', $enviados[0]['text']);
    }

    public function test_lead_dentro_e_ninguem_nosso_avisa_quem_esta_esperando(): void
    {
        $this->salaCom(['Vinicius Souza']);
        $this->reuniao();

        $this->artisan('meetings:noshow-tick')->assertSuccessful();

        $this->assertStringContainsString('Vinicius Souza está na reunião', $this->enviados()[0]['text']);
    }

    public function test_equipe_na_sala_nao_alarma(): void
    {
        $this->salaCom(['Vinicius Souza', 'Bancalize']);
        $this->reuniao();

        $this->artisan('meetings:noshow-tick')->assertSuccessful();

        $this->assertSame([], $this->enviados());
    }

    public function test_repete_no_maximo_tres_vezes(): void
    {
        $this->salaCom([]);
        $this->reuniao('2026-11-30 10:33:00');

        foreach (range(0, 30) as $i) {
            Carbon::setTestNow(Carbon::parse('2026-11-30 10:33:00', 'America/Sao_Paulo')->addMinutes($i));
            $this->artisan('meetings:noshow-tick')->assertSuccessful();
        }

        $this->assertCount(3, $this->enviados());
    }

    public function test_anfitriao_sem_google_avisa_antes_e_no_inicio(): void
    {
        $semGoogle = User::create(['name' => 'Maysa Lima', 'email' => 'maysa-'.Str::random(4).'@teste.dev',
            'password' => bcrypt('x'), 'company_id' => $this->co->id]);
        $semGoogle->forceFill(['is_host' => false])->save(); // o aviso diário tem teste próprio
        $this->salaCom([]);

        Carbon::setTestNow(Carbon::parse('2026-11-29 21:00:00', 'America/Sao_Paulo')); // fora do horário comercial
        $this->reuniao('2026-11-30 10:30:00', $semGoogle);
        $this->artisan('meetings:noshow-tick')->assertSuccessful();

        Carbon::setTestNow(Carbon::parse('2026-11-30 10:33:00', 'America/Sao_Paulo'));
        $this->artisan('meetings:noshow-tick')->assertSuccessful();

        $textos = array_column($this->enviados(), 'text');
        $this->assertCount(2, $textos);
        $this->assertStringContainsString('anfitrião sem Google', $textos[0]);
        $this->assertStringContainsString('Maysa Lima', $textos[0]);
        $this->assertStringContainsString('sem como ver a sala', $textos[1]);
    }

    public function test_anfitriao_ativo_sem_google_recebe_um_aviso_por_dia(): void
    {
        $u = User::create(['name' => 'Maysa Lima', 'email' => 'maysa-'.Str::random(4).'@teste.dev',
            'password' => bcrypt('x'), 'company_id' => $this->co->id]);
        $u->forceFill(['is_host' => true, 'agenda_ativa' => true])->save();

        $this->artisan('meetings:noshow-tick')->assertSuccessful();
        $this->artisan('meetings:noshow-tick')->assertSuccessful();

        $enviados = $this->enviados();
        $this->assertCount(1, $enviados);
        $this->assertStringContainsString('Maysa Lima está como anfitrião ativo', $enviados[0]['text']);
    }

    public function test_reuniao_interna_e_empresa_sem_destino_nao_mandam_nada(): void
    {
        $this->salaCom([]);
        $this->reuniao(comLead: false);

        $outra = Company::create(['name' => 'Outra', 'slug' => 'o-'.Str::random(5), 'is_active' => true]);
        $hostOutra = User::create(['name' => 'Fulano', 'email' => 'f-'.Str::random(4).'@teste.dev',
            'password' => bcrypt('x'), 'company_id' => $outra->id]);
        $hostOutra->forceFill(['google_refresh_token' => 'rt'])->save();
        app(Tenancy::class)->run($outra->id, function () use ($hostOutra) {
            $conv = Conversation::create(['slug' => 'l-'.uniqid(), 'name' => 'Lead', 'initials' => 'L', 'color' => '#000']);
            Meeting::create(['conversation_id' => $conv->id, 'user_id' => $hostOutra->id, 'title' => 'R',
                'meet_link' => 'https://meet.google.com/abc-defg-hij',
                'starts_at' => now()->subMinutes(5), 'ends_at' => now()->addHour()]);
        });

        $this->artisan('meetings:noshow-tick')->assertSuccessful();

        $this->assertSame([], $this->enviados());
    }

    public function test_simulacao_nao_envia_nem_cria_tarefa(): void
    {
        $this->salaCom([]);
        $this->reuniao();

        $this->artisan('meetings:noshow-tick --simular')
            ->expectsOutputToContain('Ninguém da equipe na sala')
            ->assertSuccessful();

        $this->assertSame([], $this->enviados());
        $this->assertSame(0, \App\Models\Task::withoutGlobalScopes()->count());

        // E a simulação não segura o alarme de verdade.
        $this->artisan('meetings:noshow-tick')->assertSuccessful();
        $this->assertCount(1, $this->enviados());
    }
}
