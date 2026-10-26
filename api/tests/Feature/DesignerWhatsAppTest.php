<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MarketingAutopilot;
use App\Models\MarketingAutopilotEvent;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeRequest;
use App\Models\MarketingCredential;
use App\Models\WaAccount;
use App\Services\Marketing\Autopilot;
use App\Services\Marketing\AutopilotBrain;
use App\Services\Marketing\Avisos;
use App\Services\Marketing\DesignerAgente;
use App\Services\Marketing\Gestor;
use App\Support\FacebookAds;
use App\Support\HtmlRender;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * O dono pede criativo pelo WhatsApp, recebe a peça lá, manda exemplo, pede ajuste e aprova.
 */
class DesignerWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Company $co;

    private WaAccount $evo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        config(['services.evolution.webhook_token' => 'tok-webhook', 'services.evolution.url' => 'http://evo.test']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'WA-ENVIADA']])]);

        $this->co = Company::create(['name' => 'Dono', 'slug' => 'dono-'.Str::random(5), 'is_active' => true]);
        app(Tenancy::class)->set($this->co->id);
        MarketingCredential::create(['access_token' => 'tok', 'ad_account_id' => '123', 'page_id' => '999']);
        $this->evo = WaAccount::create(['name' => 'Vertice', 'instance' => 'vertice-'.Str::random(4), 'phone' => '554191639682',
            'role' => 'outreach', 'provider' => 'evolution', 'is_active' => true, 'avatars_only' => true]);
        MarketingAutopilot::create(['enabled' => true, 'alert_phone' => '5541996578036']);
    }

    private function gestor(): Gestor
    {
        $cfg = MarketingAutopilot::atual();
        $fb = FacebookAds::make();

        return new Gestor($cfg, $fb, new Autopilot($cfg, $fb, new AutopilotBrain), new Avisos($cfg));
    }

    public function test_pedido_do_dono_guarda_exemplos_e_ajuste_e_criativo_dele_so_sobe_com_aprovacao(): void
    {
        Storage::put('marketing/dono/exemplo.jpg', 'x');
        MarketingCreative::create(['name' => 'Criativo 5', 'number' => 5, 'path' => 'marketing/creatives/c5.png', 'status' => MarketingCreative::TESTANDO,
            'source' => 'ia', 'brief' => ['aguardando_dono' => true]]);

        $r = $this->gestor()->pedirCriativo('troca o verde por azul', [], [], 1, 'pedido do dono',
            [Storage::path('marketing/dono/exemplo.jpg'), 'marketing/dono/exemplo.jpg', '/etc/passwd'], 5, true);
        $this->assertStringContainsString('WhatsApp', $r);

        $p = MarketingCreativeRequest::sole();
        $this->assertTrue($p->para_dono);
        $this->assertSame(5, $p->ajustar_criativo);
        $this->assertSame(['marketing/dono/exemplo.jpg'], $p->imagens);

        // Sem a aprovação do dono, nem o gestor sobe.
        $this->assertStringStartsWith('RECUSADO', $this->gestor()->publicar(5, 'texto', null, null, null, 'acho bom'));
    }

    public function test_imagem_do_dono_respondendo_um_criativo_vira_mensagem_com_o_contexto(): void
    {
        MarketingAutopilotEvent::create(['kind' => 'enviado_dono', 'message' => 'x', 'data' => ['wa_id' => 'WA-CRIATIVO', 'criativo' => 7, 'pedido' => 1]]);

        $this->postJson('/api/wpp/webhook?token=tok-webhook', [
            'event' => 'messages.upsert', 'instance' => $this->evo->instance,
            'data' => ['key' => ['remoteJid' => '554196578036@s.whatsapp.net', 'fromMe' => false, 'id' => 'IMG1'], 'messageTimestamp' => time(),
                'message' => ['imageMessage' => ['caption' => 'faz nesse estilo', 'contextInfo' => ['stanzaId' => 'WA-CRIATIVO']]]],
        ])->assertJson(['handled' => 'gestor']);

        $e = MarketingAutopilotEvent::where('kind', 'dono')->sole();
        $this->assertSame('faz nesse estilo', $e->message);
        $this->assertTrue($e->data['imagem']);
        $this->assertSame('WA-CRIATIVO', $e->data['responde_a']);
    }

    public function test_designer_renderiza_html_entrega_e_manda_ao_dono(): void
    {
        if (! HtmlRender::disponivel()) {
            $this->markTestSkipped('navegador headless não instalado');
        }
        $p = MarketingCreativeRequest::create(['brief' => 'peça de teste', 'quantidade' => 1, 'motivo' => 'teste', 'para_dono' => true,
            'status' => MarketingCreativeRequest::PRODUZINDO]);
        Storage::put("marketing/html/{$p->id}-teste.html", '<html><body style="margin:0;width:1080px;height:1350px;background:#F2F0E9">'
            .'<h1 style="font:140px Anton">SUA MARCA</h1></body></html>');

        putenv('GESTOR_EMPRESA='.$this->co->id);
        putenv('GESTOR_PEDIDO='.$p->id);
        try {
            $this->artisan('designer', ['acao' => 'render', '--html' => Storage::path("marketing/html/{$p->id}-teste.html"), '--manchete' => 'SUA MARCA'])
                ->expectsOutputToContain('2160×2700')->assertSuccessful();
            $id = MarketingAutopilotEvent::where('kind', 'rascunho')->sole()->data['rascunho'];
            $this->assertSame([2160, 2700], array_slice(getimagesize(Storage::path("marketing/rascunhos/{$p->id}-{$id}.png")), 0, 2));

            // HTML fora da pasta de trabalho não passa.
            $this->artisan('designer', ['acao' => 'render', '--html' => '/etc/hostname'])->expectsOutputToContain('Passe --html=');

            $this->artisan('designer', ['acao' => 'entregar', 'rascunhos' => $id, '--mensagem' => 'Fale com a gente', '--nota' => 'testa a marca'])
                ->assertSuccessful();
        } finally {
            putenv('GESTOR_EMPRESA');
            putenv('GESTOR_PEDIDO');
        }

        $c = MarketingCreative::sole();
        $this->assertTrue($c->brief['aguardando_dono']);
        $this->assertTrue(Storage::exists($c->brief['html']));

        // A entrega volta para o dono no WhatsApp, ligada ao id da mensagem.
        $mostrar = new \ReflectionMethod(DesignerAgente::class, 'mostrarAoDono');
        $mostrar->invoke(new DesignerAgente(MarketingAutopilot::atual()), $p->fresh());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/message/sendMedia/') && $r['number'] === '5541996578036');
        $this->assertSame('WA-ENVIADA', MarketingAutopilotEvent::where('kind', 'enviado_dono')->sole()->data['wa_id']);
    }
}
