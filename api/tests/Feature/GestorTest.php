<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MarketingAutopilot;
use App\Models\MarketingCreative;
use App\Models\MarketingCredential;
use App\Models\WaAccount;
use App\Services\Marketing\Autopilot;
use App\Services\Marketing\AutopilotBrain;
use App\Services\Marketing\Avisos;
use App\Services\Marketing\Gestor;
use App\Support\FacebookAds;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * As ferramentas do agente gestor: quem decide é o agente, mas cada teste fixa um limite de
 * dinheiro que nenhuma decisão dele atravessa — teto máximo, escalar só com custo por
 * qualificado na meta, passo de verba, religar sem ressuscitar o irmão ruim, simulação sem
 * escrita — e a vigia sem IA que o acorda.
 */
class GestorTest extends TestCase
{
    use RefreshDatabase;

    private Company $co;

    /** @var list<array<string, mixed>> anúncios da conta fake */
    private array $ads = [];

    /** @var array<string, array<string, float>> date_preset => ad_id => gasto */
    private array $gasto = [];

    private string $saldo = 'Saldo disponível (R$5.000,00 BRL)';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude.oauth_token' => 'sk-ant-oat01-teste', 'services.evolution.url' => 'http://evo.test']);

        $this->co = Company::create(['name' => 'Gestor', 'slug' => 'gestor-'.Str::random(5), 'is_active' => true]);
        app(Tenancy::class)->set($this->co->id);

        MarketingCredential::create(['access_token' => 'tok', 'ad_account_id' => '123', 'page_id' => '999']);
        WaAccount::create(['name' => 'Principal', 'instance' => 'p-'.Str::random(5), 'phone' => '554195797386', 'role' => 'primary', 'provider' => 'cloud']);
        WaAccount::create(['name' => 'Evo', 'instance' => 'evo-aviso', 'phone' => '554191639682', 'role' => 'outreach', 'provider' => 'evolution', 'is_active' => true]);

        $this->fakeMeta();
    }

    private function fakeMeta(): void
    {
        Http::fake(function (Request $r) {
            $url = urldecode($r->url());
            if (str_contains($url, '/message/sendText')) {
                return Http::response(['key' => ['id' => 'wa-1']]);
            }
            if ($r->method() === 'POST') {
                return Http::response(['success' => true]);
            }
            if (str_contains($url, '/insights')) {
                preg_match('/date_preset=(\w+)/', $url, $m);

                return Http::response(['data' => collect($this->gasto[$m[1] ?? ''] ?? [])
                    ->map(fn ($g, $id) => ['ad_id' => $id, 'spend' => (string) $g])->values()->all()]);
            }
            if (str_contains($url, '/ads') && str_contains($url, 'created_time')) {
                return Http::response(['data' => $this->ads]);
            }
            if (str_contains($url, '/ads') && str_contains($url, 'filtering')) {
                return Http::response(['data' => array_values(array_filter($this->ads, fn ($a) => $a['effective_status'] === 'ACTIVE'))]);
            }
            if (str_contains($url, 'ids=')) {
                return Http::response([]);
            }
            if (str_contains($url, '/ads')) {
                return Http::response(['data' => []]);
            }

            return Http::response(['funding_source_details' => ['display_string' => $this->saldo]]);
        });
    }

    /** Anúncio ABO de um conjunto só, com orçamento no conjunto. */
    private function anuncio(string $id, float $diario, string $efetivo = 'ACTIVE', string $campanha = '', string $proprio = 'ACTIVE'): void
    {
        $campanha = $campanha ?: "camp-{$id}";
        $this->ads[] = [
            'id' => $id, 'name' => "Anúncio {$id}", 'status' => $proprio, 'effective_status' => $efetivo,
            'created_time' => now()->subDays(4)->toIso8601String(),
            'adset' => ['id' => "conj-{$id}", 'name' => "Conjunto {$id}", 'status' => 'ACTIVE', 'daily_budget' => (string) ($diario * 100),
                'targeting' => ['age_min' => 18, 'age_max' => 54, 'genders' => [1], 'publisher_platforms' => ['instagram']]],
            'campaign' => ['id' => $campanha, 'name' => "Campanha {$campanha}", 'status' => $efetivo === 'CAMPAIGN_PAUSED' ? 'PAUSED' : 'ACTIVE'],
        ];
    }

    /** @param  list<bool|null>  $triagem */
    private function leads(string $adId, array $triagem): void
    {
        foreach ($triagem as $i => $q) {
            DB::table('conversations')->insert([
                'company_id' => $this->co->id, 'name' => "Lead {$i}", 'slug' => 'wa-55419'.Str::random(8), 'initials' => 'L',
                'color' => '#888', 'stage' => 'novo', 'qualified' => $q,
                'custom_fields' => json_encode(['anuncio' => ['id' => $adId]]),
                'created_at' => now()->subDay(), 'updated_at' => now(),
            ]);
        }
    }

    private function cfg(array $extra = []): MarketingAutopilot
    {
        return MarketingAutopilot::create($extra + ['enabled' => true, 'daily_cap' => 100, 'max_cap' => 200,
            'target_cpq' => 30, 'max_new_per_day' => 0, 'alert_phone' => '5541999990000'])->fresh();
    }

    private function gestor(MarketingAutopilot $cfg, bool $simular = false): Gestor
    {
        $fb = FacebookAds::make();

        return new Gestor($cfg, $fb, new Autopilot($cfg, $fb, new AutopilotBrain, $simular), new Avisos($cfg, $simular), $simular);
    }

    /** @return list<string> ids que receberam POST status=X, na ordem */
    private function comStatus(string $status): array
    {
        return collect(Http::recorded(fn (Request $r) => $r->method() === 'POST' && ($r->data()['status'] ?? null) === $status))
            ->map(fn ($p) => basename(parse_url($p[0]->url(), PHP_URL_PATH)))->values()->all();
    }

    public function test_so_escala_o_teto_acima_do_normal_com_custo_por_qualificado_na_meta(): void
    {
        $this->anuncio('a', 50);
        $this->gasto['last_3d'] = ['a' => 150];
        $this->leads('a', [true, false, false]); // R$ 150 por 1 qualificado: fora da meta de R$ 30
        $cfg = $this->cfg();

        $r = $this->gestor($cfg)->teto(180, 'escalar');
        $this->assertStringStartsWith('RECUSADO', $r);
        $this->assertSame(100.0, $cfg->fresh()->tetoVigente());

        $this->leads('a', [true, true, true, true, true, true, true]); // 8 qualificados → R$ 18,75
        $this->gestor($cfg)->teto(180, 'escalar');
        $this->assertSame(180.0, $cfg->fresh()->tetoVigente());
    }

    public function test_teto_nunca_passa_do_maximo_e_baixar_e_livre(): void
    {
        $this->anuncio('a', 50);
        $this->leads('a', array_fill(0, 10, true));
        $cfg = $this->cfg();

        $this->gestor($cfg)->teto(5000, 'x');
        $this->assertSame(200.0, $cfg->fresh()->tetoVigente());

        $this->gestor($cfg)->teto(40, 'nada qualifica');
        $this->assertSame(40.0, $cfg->fresh()->tetoVigente());
    }

    public function test_verba_respeita_o_passo_maximo_e_repetir_em_24h_exige_forcar(): void
    {
        $this->anuncio('a', 100);
        $cfg = $this->cfg(['daily_cap' => 400, 'max_cap' => 400]);

        $r = $this->gestor($cfg)->verba('conj-a', 300, 'escalar');
        $this->assertStringContainsString('limitei', $r);
        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/conj-a') && (int) $r['daily_budget'] === 15000);

        $r = $this->gestor($cfg)->verba('conj-a', 120, 'de novo');
        $this->assertStringStartsWith('ATENÇÃO', $r);
        $this->assertSame(1, collect(Http::recorded(fn (Request $r) => isset($r->data()['daily_budget'])))->count());

        $this->gestor($cfg)->verba('conj-a', 120, 'de novo', forcar: true);
        $this->assertSame(2, collect(Http::recorded(fn (Request $r) => isset($r->data()['daily_budget'])))->count());
    }

    public function test_verba_que_passaria_do_teto_e_recusada(): void
    {
        $this->anuncio('a', 90);
        $r = $this->gestor($this->cfg())->verba('conj-a', 130, 'x');

        $this->assertStringStartsWith('RECUSADO', $r);
        Http::assertNotSent(fn (Request $r) => isset($r->data()['daily_budget']));
    }

    public function test_religar_nao_ressuscita_o_irmao_que_estava_na_mesma_campanha(): void
    {
        $this->anuncio('bom', 25, 'CAMPAIGN_PAUSED', 'camp-x');
        $this->anuncio('irmao', 25, 'CAMPAIGN_PAUSED', 'camp-x');

        $r = $this->gestor($this->cfg())->religar('bom', 'qualificava a R$ 9');

        $this->assertStringContainsString('Anúncio irmao', $r);
        $this->assertSame(['irmao'], $this->comStatus('PAUSED'));
        $this->assertSame(['bom', 'camp-x'], $this->comStatus('ACTIVE'));
        // O irmão é pausado ANTES da campanha ligar — nunca há um minuto com ele entregando.
        $ordem = collect(Http::recorded(fn (Request $r) => $r->method() === 'POST'))->map(fn ($p) => basename(parse_url($p[0]->url(), PHP_URL_PATH)))->values();
        $this->assertLessThan($ordem->search('camp-x'), $ordem->search('irmao'));
    }

    public function test_religar_que_passaria_do_teto_e_recusado(): void
    {
        $this->anuncio('ligado', 90);
        $this->anuncio('parado', 25, 'PAUSED', '', 'PAUSED');

        $r = $this->gestor($this->cfg())->religar('parado', 'x');

        $this->assertStringStartsWith('RECUSADO', $r);
        $this->assertSame([], $this->comStatus('ACTIVE'));
    }

    public function test_publicar_que_passaria_do_teto_e_recusado(): void
    {
        $this->anuncio('ligado', 90);
        MarketingCreative::create(['name' => 'Criativo 5', 'number' => 5, 'path' => 'x.png', 'status' => 'testando']);

        $r = $this->gestor($this->cfg())->publicar(5, 'Fale com a gente', null, null, null, 'testar');

        $this->assertStringStartsWith('RECUSADO', $r);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_simulacao_nao_escreve_na_meta(): void
    {
        $this->anuncio('a', 50);

        $r = $this->gestor($this->cfg(), simular: true)->pausar('a', 'teste');

        $this->assertStringStartsWith('[SIMULAÇÃO', $r);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_limitado_aos_proprios_anuncios_nao_toca_na_campanha_manual(): void
    {
        $this->anuncio('manual', 50);

        $r = $this->gestor($this->cfg(['manage_whole_account' => false]))->pausar('manual', 'x');

        $this->assertStringStartsWith('RECUSADO', $r);
        $this->assertSame([], $this->comStatus('PAUSED'));
    }

    public function test_vigia_acorda_o_agente_quando_passa_do_teto_maximo_sem_cortar_nada_sozinha(): void
    {
        $this->anuncio('a', 150);
        $this->anuncio('b', 100);

        $acordar = $this->gestor($this->cfg())->vigiar();

        $this->assertStringContainsString('acima do teto máximo', $acordar);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && ! str_contains($r->url(), 'sendText'));
    }

    public function test_vigia_acorda_o_agente_quando_a_conta_e_recarregada(): void
    {
        $this->anuncio('a', 50);
        $cfg = $this->cfg();
        $this->saldo = 'Saldo disponível (R$0,00 BRL)';
        $this->assertNull($this->gestor($cfg)->vigiar());

        $this->saldo = 'Saldo disponível (R$300,00 BRL)';
        $this->assertStringContainsString('recarregada', (string) $this->gestor($cfg)->vigiar());
    }

    public function test_aviso_de_saldo_baixo_chega_uma_vez_e_nao_a_cada_vigia(): void
    {
        $this->anuncio('a', 80);
        $this->saldo = 'Saldo disponível (R$100,00 BRL)';
        $cfg = $this->cfg();

        $this->gestor($cfg)->vigiar();
        $this->gestor($cfg)->vigiar();

        $envios = Http::recorded(fn (Request $r) => str_contains($r->url(), '/message/sendText'))->values();
        $this->assertCount(1, $envios);
        $this->assertSame('5541999990000', $envios[0][0]['number']);
        $this->assertStringContainsString('evo-aviso', $envios[0][0]->url());
    }

    public function test_ferramenta_do_agente_exige_motivo_e_empresa_do_ambiente(): void
    {
        $this->anuncio('a', 50);
        $this->cfg();

        $this->artisan('gestor', ['acao' => 'conta'])->assertFailed();

        putenv('GESTOR_EMPRESA='.$this->co->id);
        try {
            $this->artisan('gestor', ['acao' => 'pausar', 'alvo' => 'a'])->expectsOutputToContain('Falta --motivo')->assertFailed();
            $this->artisan('gestor', ['acao' => 'pausar', 'alvo' => 'a', '--motivo' => 'R$ 80 sem qualificado'])->expectsOutputToContain('Pausado')->assertSuccessful();
        } finally {
            putenv('GESTOR_EMPRESA');
        }
        $this->assertSame(['a'], $this->comStatus('PAUSED'));
    }
}
