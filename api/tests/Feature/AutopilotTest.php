<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MarketingAutoAd;
use App\Models\MarketingAutopilot;
use App\Models\MarketingCreative;
use App\Models\MarketingCredential;
use App\Models\WaAccount;
use App\Services\Marketing\Autopilot;
use App\Services\Marketing\AutopilotBrain;
use App\Support\FacebookAds;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Piloto automático de anúncios: o que ele pode e não pode fazer com dinheiro.
 *
 * Cada teste fixa uma regra que já custou caro nesta conta quando era decisão manual:
 * teto da conta inteira, corte por qualificação do CRM, vida útil do criativo, conta
 * pré-paga sem saldo e simulação que não pode tocar na Meta.
 */
class AutopilotTest extends TestCase
{
    use RefreshDatabase;

    private Company $co;

    /** Orçamento diário já comprometido na conta, devolvido pela Meta fake (R$). */
    private float $comprometido = 0;

    private string $saldo = 'Saldo disponível (R$500,00 BRL)';

    /** @var array<string, string> ad_id => effective_status */
    private array $status = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude.oauth_token' => 'sk-ant-oat01-teste']);
        Storage::fake();

        $this->co = Company::create(['name' => 'Piloto', 'slug' => 'piloto-'.Str::random(5), 'is_active' => true]);
        app(Tenancy::class)->set($this->co->id);

        MarketingCredential::create(['access_token' => 'tok', 'ad_account_id' => '123', 'page_id' => '999']);
        WaAccount::create(['name' => 'Principal', 'instance' => 'p-'.Str::random(5), 'phone' => '554195797386', 'role' => 'primary']);

        $this->fakeMeta();
        // A IA responde o plano: copy para o criativo de estoque nº 50.
        Process::fake(['*' => Process::result(output: json_encode([
            'copys' => [['criativo' => 50, 'mensagem' => 'Você já atende lojistas? Fale com a gente.', 'titulo' => 'Para quem já opera']],
            'pecas' => [],
        ]))]);
    }

    private function fakeMeta(): void
    {
        Http::fake(function (Request $r) {
            $url = $r->url();
            if ($r->method() === 'POST') {
                return match (true) {
                    str_contains($url, '/campaigns') => Http::response(['id' => 'camp-novo']),
                    str_contains($url, '/adsets') => Http::response(['id' => 'conj-novo']),
                    str_contains($url, '/adimages') => Http::response(['images' => ['x' => ['hash' => 'hash-novo']]]),
                    str_contains($url, '/adcreatives') => Http::response(['id' => 'cr-novo']),
                    str_contains($url, '/ads') => Http::response(['id' => 'ad-novo']),
                    default => Http::response(['success' => true]), // definirStatus
                };
            }
            if (str_contains($url, 'ids=')) {
                return Http::response(collect($this->status)->map(fn ($s) => ['effective_status' => $s])->all());
            }
            if (str_contains($url, '/insights')) {
                return Http::response(['data' => [['ad_id' => 'ad-velho', 'spend' => '40', 'impressions' => '1000', 'clicks' => '30']]]);
            }
            if (str_contains($url, '/ads') && str_contains($url, 'filtering')) {
                return Http::response(['data' => $this->comprometido > 0
                    ? [['adset' => ['id' => 'a1', 'name' => 'antigo', 'daily_budget' => (string) ($this->comprometido * 100)], 'campaign' => ['id' => 'c1']]]
                    : []]);
            }
            if (str_contains($url, '/ads')) {
                return Http::response(['data' => []]); // anunciosPorImagem / listarAnuncios
            }

            return Http::response(['funding_source_details' => ['display_string' => $this->saldo]]); // resumoConta
        });
    }

    private function cfg(array $extra = []): MarketingAutopilot
    {
        return MarketingAutopilot::create($extra + ['enabled' => true, 'daily_cap' => 100, 'budget_per_ad' => 25, 'max_new_per_day' => 2])->fresh();
    }

    private function piloto(MarketingAutopilot $cfg, bool $simular = false): Autopilot
    {
        return new Autopilot($cfg, FacebookAds::make(), new AutopilotBrain, $simular);
    }

    private function criativoDeEstoque(): MarketingCreative
    {
        Storage::put('marketing/creatives/50.png', 'png');

        return MarketingCreative::create(['name' => 'Criativo 50', 'number' => 50, 'original_name' => '50-jornal-volume-1080x1350.png',
            'path' => 'marketing/creatives/50.png', 'mime' => 'image/png', 'status' => MarketingCreative::TESTANDO]);
    }

    private function anuncioDoPiloto(int $diasNoAr): MarketingAutoAd
    {
        $c = MarketingCreative::create(['name' => 'Criativo 10', 'number' => 10, 'path' => 'x.png', 'status' => MarketingCreative::TESTANDO]);
        $this->status['ad-velho'] = 'ACTIVE';

        return MarketingAutoAd::create(['marketing_creative_id' => $c->id, 'campaign_id' => 'camp-velha', 'adset_id' => 'conj-velho',
            'ad_id' => 'ad-velho', 'daily_budget' => 25, 'status' => MarketingAutoAd::ATIVO, 'launched_at' => now()->subDays($diasNoAr)]);
    }

    /** @param  list<bool|null>  $triagem  true = qualificado, false = desqualificado, null = sem triagem */
    private function leadsDoAnuncio(string $adId, array $triagem): void
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

    private function postsDeStatus(string $status): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && ($r->data()['status'] ?? null) === $status
            && ! preg_match('#/(campaigns|adsets|ads)$#', parse_url($r->url(), PHP_URL_PATH)))->all();
    }

    public function test_nao_cria_nada_quando_a_conta_inteira_ja_esta_no_teto(): void
    {
        $this->comprometido = 90; // campanhas antigas, fora do piloto
        $this->criativoDeEstoque();

        $this->piloto($this->cfg())->rodar();

        $this->assertSame(0, MarketingAutoAd::count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/campaigns') && $r->method() === 'POST');
        $this->assertDatabaseHas('marketing_autopilot_events', ['kind' => 'teto']);
    }

    public function test_publica_estoque_dentro_do_teto_e_liga_de_baixo_para_cima(): void
    {
        $this->comprometido = 50;
        $this->criativoDeEstoque();

        $this->piloto($this->cfg())->rodar();

        // R$ 50 de folga ÷ R$ 25 = 2 vagas, mas só há 1 criativo de estoque e nenhuma peça nova.
        $ad = MarketingAutoAd::sole();
        $this->assertSame(MarketingAutoAd::ATIVO, $ad->status);
        $this->assertSame('ad-novo', $ad->ad_id);

        $ligados = collect($this->postsDeStatus('ACTIVE'))->map(fn ($p) => basename(parse_url($p[0]->url(), PHP_URL_PATH)))->values()->all();
        $this->assertSame(['ad-novo', 'conj-novo', 'camp-novo'], $ligados);

        // Orçamento de R$ 25 no conjunto, público que qualifica e o número certo no destino.
        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/adsets')
            && $r['daily_budget'] === 2500
            && json_decode($r['targeting'], true)['age_max'] === 54
            && json_decode($r['promoted_object'], true)['whatsapp_phone_number'] === '554195797386');
    }

    public function test_pausa_quem_nao_qualifica_reprova_o_criativo_e_repoe_a_vaga(): void
    {
        $velho = $this->anuncioDoPiloto(3);
        $this->leadsDoAnuncio('ad-velho', [true, false, false, false, false, false, false, false, false, null]);
        $this->comprometido = 0; // a Meta já não conta a campanha pausada
        $this->criativoDeEstoque();

        $this->piloto($this->cfg())->rodar();

        $velho->refresh();
        $this->assertSame(MarketingAutoAd::PAUSADO, $velho->status);
        $this->assertStringContainsString('qualificação 11%', $velho->pause_reason);
        $this->assertSame(MarketingCreative::REPROVADO, $velho->creative->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/camp-velha') && $r['status'] === 'PAUSED');

        $this->assertSame(1, MarketingAutoAd::where('status', MarketingAutoAd::ATIVO)->count());
    }

    public function test_criativo_que_qualifica_sai_mesmo_assim_ao_fim_da_vida_util(): void
    {
        $velho = $this->anuncioDoPiloto(7);
        $this->leadsDoAnuncio('ad-velho', array_fill(0, 8, true));

        $this->piloto($this->cfg(['use_stock' => false, 'max_new_per_day' => 0]))->rodar();

        $velho->refresh();
        $this->assertSame(MarketingAutoAd::PAUSADO, $velho->status);
        $this->assertSame('vida útil de 7 dias', $velho->pause_reason);
        // Saiu por idade, não por ser ruim: o criativo fica VALIDADO para a IA repetir o ângulo.
        $this->assertSame(MarketingCreative::VALIDADO, $velho->creative->status);
    }

    public function test_conta_sem_saldo_nao_ganha_anuncio_novo(): void
    {
        $this->saldo = 'Saldo disponível (R$0,00 BRL)';
        $this->criativoDeEstoque();

        $this->piloto($this->cfg())->rodar();

        $this->assertSame(0, MarketingAutoAd::count());
        $this->assertDatabaseHas('marketing_autopilot_events', ['kind' => 'sem_saldo']);
    }

    public function test_simulacao_nao_escreve_nada_na_meta(): void
    {
        $this->anuncioDoPiloto(9);
        $this->criativoDeEstoque();

        $this->piloto($this->cfg(), simular: true)->rodar();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
        $this->assertSame(MarketingAutoAd::ATIVO, MarketingAutoAd::first()->status);
        $this->assertDatabaseHas('marketing_autopilot_events', ['kind' => 'publicaria', 'simulated' => true]);
        $this->assertDatabaseHas('marketing_autopilot_events', ['kind' => 'pausou', 'simulated' => true]);
    }

    public function test_status_do_painel_nunca_devolve_a_chave_da_api_de_imagem(): void
    {
        $cfg = $this->cfg(['image_provider' => 'openai', 'image_api_key' => 'sk-segredo']);

        $json = $cfg->fresh()->toArray();

        $this->assertArrayNotHasKey('image_api_key', $json);
        $this->assertTrue($json['tem_chave_imagem']);
        $this->assertNotSame('sk-segredo', DB::table('marketing_autopilots')->value('image_api_key'));
    }
}
