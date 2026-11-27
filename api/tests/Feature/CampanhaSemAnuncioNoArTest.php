<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MarketingCredential;
use App\Support\FacebookAds;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A Meta deixa a campanha "ACTIVE" com todos os anúncios pausados — o painel de marketing
 * mostrava "Ativa" em campanha que não gastava nada. A contagem de anúncios no ar por
 * campanha é o que separa as duas coisas.
 */
class CampanhaSemAnuncioNoArTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $co = Company::create(['name' => 'Mkt', 'slug' => 'mkt-'.Str::random(5), 'is_active' => true]);
        app(Tenancy::class)->set($co->id);
        MarketingCredential::create(['access_token' => 'tok', 'ad_account_id' => '123', 'page_id' => '999']);

        Http::fake(['*' => Http::response(['data' => [
            ['campaign_id' => 'c-roda', 'effective_status' => 'ACTIVE', 'creative' => ['thumbnail_url' => 'https://x/ativa.jpg']],
            ['campaign_id' => 'c-roda', 'effective_status' => 'PAUSED', 'creative' => ['thumbnail_url' => 'https://x/velha.jpg']],
            ['campaign_id' => 'c-parada', 'effective_status' => 'PAUSED', 'creative' => ['thumbnail_url' => 'https://x/parada.jpg']],
            ['campaign_id' => 'c-parada', 'effective_status' => 'ADSET_PAUSED'],
            ['campaign_id' => 'c-sem-arte', 'effective_status' => 'ACTIVE'],
        ]])]);
    }

    public function test_conta_os_anuncios_no_ar_de_cada_campanha(): void
    {
        $r = FacebookAds::make()->anunciosPorCampanha();

        $this->assertSame(['c-roda' => 1, 'c-parada' => 0, 'c-sem-arte' => 1], $r['rodando']);
    }

    public function test_campanha_fora_da_lista_nao_vira_zero(): void
    {
        // Não saber não é zero: zero pintaria de pausada uma campanha que talvez rode.
        $this->assertArrayNotHasKey('c-desconhecida', FacebookAds::make()->anunciosPorCampanha()['rodando']);
    }

    public function test_miniatura_continua_preferindo_o_anuncio_no_ar(): void
    {
        $this->assertSame(
            ['c-roda' => 'https://x/ativa.jpg', 'c-parada' => 'https://x/parada.jpg'],
            FacebookAds::make()->miniaturasPorCampanha(),
        );
    }
}
