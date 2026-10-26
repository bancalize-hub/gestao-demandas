<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MarketingAutopilot;
use App\Models\MarketingAutopilotEvent;
use App\Models\MarketingCredential;
use App\Models\WaAccount;
use App\Services\Marketing\Autopilot;
use App\Services\Marketing\AutopilotBrain;
use App\Services\Marketing\Avisos;
use App\Services\Marketing\Gestor;
use App\Services\Marketing\GestorBrain;
use App\Support\FacebookAds;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gestor de tráfego: a IA decide, mas cada teste fixa uma trava que nenhuma resposta dela
 * pode atravessar — teto máximo, passo de verba, trava de 24h, escalar só com custo por
 * qualificado na meta, religar sem ressuscitar o irmão ruim, simulação sem escrita.
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

    /** @var array<string, mixed> resposta da IA */
    private array $decisao = ['analise' => 'ok', 'teto' => null, 'acoes' => []];

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

    private function revisar(MarketingAutopilot $cfg, bool $simular = false): array
    {
        Process::fake(['*' => Process::result(output: json_encode($this->decisao + ['copys' => [], 'pecas' => []]))]);
        $fb = FacebookAds::make();
        $gestor = new Gestor($cfg, $fb, new Autopilot($cfg, $fb, new AutopilotBrain, $simular), new GestorBrain, new Avisos($cfg, $simular), $simular);

        return $gestor->revisar();
    }

    /** @return list<string> ids que receberam POST status=X, na ordem */
    private function comStatus(string $status): array
    {
        return collect(Http::recorded(fn (Request $r) => $r->method() === 'POST' && ($r->data()['status'] ?? null) === $status))
            ->map(fn ($p) => basename(parse_url($p[0]->url(), PHP_URL_PATH)))->values()->all();
    }

    public function test_teto_maximo_estourado_corta_o_pior_custo_por_qualificado_sem_esperar_a_ia(): void
    {
        $this->anuncio('ruim', 150);
        $this->anuncio('bom', 100);
        $this->gasto['last_7d'] = ['ruim' => 300, 'bom' => 120];
        $this->leads('bom', [true, true, true, true]);

        $this->revisar($this->cfg());

        // R$ 250 comprometidos com máximo de R$ 200: sai o que não qualificou, e só ele.
        $this->assertSame(['ruim'], $this->comStatus('PAUSED'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/message/sendText') && str_contains($r['text'], 'teto máximo'));
    }

    public function test_verba_respeita_o_passo_maximo_e_a_trava_de_24h(): void
    {
        $this->anuncio('a', 100);
        $this->decisao['acoes'] = [['acao' => 'verba', 'orcamento' => 'conj-a', 'valor' => 300, 'motivo' => 'escalar']];
        $cfg = $this->cfg(['daily_cap' => 400, 'max_cap' => 400]);

        $this->revisar($cfg);
        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/conj-a') && (int) $r['daily_budget'] === 15000);

        // Mesma ordem uma hora depois: o conjunto ainda está travado.
        $this->revisar($cfg);
        $this->assertSame(1, collect(Http::recorded(fn (Request $r) => isset($r->data()['daily_budget'])))->count());
        $this->assertDatabaseHas('marketing_autopilot_events', ['kind' => 'recusou']);
    }

    public function test_so_escala_o_teto_acima_do_normal_com_custo_por_qualificado_na_meta(): void
    {
        $this->anuncio('a', 50);
        $this->gasto['last_3d'] = ['a' => 150];
        $this->leads('a', [true, false, false]); // R$ 150 por 1 qualificado: fora da meta de R$ 30
        $this->decisao['teto'] = 180;

        $cfg = $this->cfg();
        $this->revisar($cfg);
        $this->assertNull($cfg->fresh()->current_cap);
        $this->assertSame(100.0, $cfg->fresh()->tetoVigente());

        $this->leads('a', [true, true, true, true, true, true, true]); // 8 qualificados → R$ 18,75
        $this->revisar($cfg);
        $this->assertSame(180.0, $cfg->fresh()->tetoVigente());
    }

    public function test_teto_nunca_passa_do_maximo_mesmo_se_a_ia_pedir(): void
    {
        $this->anuncio('a', 50);
        $this->leads('a', array_fill(0, 10, true));
        $this->decisao['teto'] = 5000;

        $cfg = $this->cfg();
        $this->revisar($cfg);

        $this->assertSame(200.0, $cfg->fresh()->tetoVigente());
    }

    public function test_religar_nao_ressuscita_o_irmao_que_estava_na_mesma_campanha(): void
    {
        $this->anuncio('bom', 25, 'CAMPAIGN_PAUSED', 'camp-x');
        $this->anuncio('irmao', 25, 'CAMPAIGN_PAUSED', 'camp-x');
        $this->gasto['last_30d'] = ['bom' => 80, 'irmao' => 80];
        $this->decisao['acoes'] = [['acao' => 'religar', 'anuncio' => 'bom', 'motivo' => 'qualificava a R$ 9']];

        $this->revisar($this->cfg());

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
        $this->gasto['last_30d'] = ['parado' => 50];
        $this->decisao['acoes'] = [['acao' => 'religar', 'anuncio' => 'parado', 'motivo' => 'x']];

        $this->revisar($this->cfg());

        $this->assertSame([], $this->comStatus('ACTIVE'));
        $this->assertDatabaseHas('marketing_autopilot_events', ['kind' => 'recusou']);
    }

    public function test_simulacao_nao_escreve_na_meta_nem_manda_whatsapp(): void
    {
        $this->anuncio('a', 300);
        $this->decisao['acoes'] = [['acao' => 'pausar', 'anuncio' => 'a', 'motivo' => 'teste']];

        $this->revisar($this->cfg(), simular: true);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
        $this->assertTrue(MarketingAutopilotEvent::where('kind', 'acao')->where('simulated', true)->exists());
    }

    public function test_aviso_de_saldo_baixo_chega_uma_vez_e_nao_a_cada_hora(): void
    {
        $this->anuncio('a', 80);
        $this->saldo = 'Saldo disponível (R$100,00 BRL)';
        $cfg = $this->cfg();

        $this->revisar($cfg);
        $this->revisar($cfg);

        $envios = Http::recorded(fn (Request $r) => str_contains($r->url(), '/message/sendText'))->values();
        $this->assertCount(1, $envios);
        $this->assertSame('5541999990000', $envios[0][0]['number']);
        $this->assertStringContainsString('evo-aviso', $envios[0][0]->url());
    }

    public function test_limitado_aos_proprios_anuncios_nao_toca_na_campanha_manual(): void
    {
        $this->anuncio('manual', 50);
        $this->decisao['acoes'] = [['acao' => 'pausar', 'anuncio' => 'manual', 'motivo' => 'x']];

        $this->revisar($this->cfg(['manage_whole_account' => false]));

        $this->assertSame([], $this->comStatus('PAUSED'));
    }
}
