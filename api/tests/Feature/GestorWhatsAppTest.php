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
use App\Services\Marketing\GestorWhatsApp;
use App\Support\FacebookAds;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O dono manda no gestor pelo WhatsApp — e SÓ o dono: mensagem do telefone cadastrado vira
 * ordem para o gestor (não conversa do CRM), a de qualquer outro segue o caminho normal.
 */
class GestorWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Company $co;

    private WaAccount $evo;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.evolution.webhook_token' => 'tok-webhook', 'services.evolution.url' => 'http://evo.test']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'x']])]);

        $this->co = Company::create(['name' => 'Dono', 'slug' => 'dono-'.Str::random(5), 'is_active' => true]);
        app(Tenancy::class)->set($this->co->id);
        MarketingCredential::create(['access_token' => 'tok', 'ad_account_id' => '123', 'page_id' => '999']);
        $this->evo = WaAccount::create(['name' => 'Vertice', 'instance' => 'vertice-'.Str::random(4), 'phone' => '554191639682',
            'role' => 'outreach', 'provider' => 'evolution', 'is_active' => true, 'avatars_only' => true]);
        MarketingAutopilot::create(['enabled' => true, 'alert_phone' => '5541996578036']);
    }

    private function webhook(string $jid, string $texto, string $id = 'MSG1'): TestResponse
    {
        return $this->postJson('/api/wpp/webhook?token=tok-webhook', [
            'event' => 'messages.upsert',
            'instance' => $this->evo->instance,
            'data' => ['key' => ['remoteJid' => $jid, 'fromMe' => false, 'id' => $id], 'message' => ['conversation' => $texto], 'messageTimestamp' => time()],
        ]);
    }

    public function test_mensagem_do_dono_vira_ordem_para_o_gestor_e_nao_conversa_do_crm(): void
    {
        // O WhatsApp costuma entregar celular BR SEM o 9º dígito: tem que casar mesmo assim.
        $this->webhook('554196578036@s.whatsapp.net', 'Não suba nada com cara de banco')->assertJson(['handled' => 'gestor']);

        $e = MarketingAutopilotEvent::where('kind', 'dono')->sole();
        $this->assertSame('Não suba nada com cara de banco', $e->message);
        $this->assertFalse($e->data['lido']);
        $this->assertSame(0, DB::table('conversations')->count());

        // Reentrega do mesmo upsert pela Evolution não gera segunda ordem (nem segunda resposta).
        $this->webhook('554196578036@s.whatsapp.net', 'Não suba nada com cara de banco');
        $this->assertSame(1, MarketingAutopilotEvent::where('kind', 'dono')->count());
    }

    public function test_outro_remetente_nao_manda_no_gestor(): void
    {
        $this->webhook('5511988887777@s.whatsapp.net', 'pause tudo', 'MSG2')->assertJson(['ignored' => 'avatars-only']);

        $this->assertSame(0, MarketingAutopilotEvent::where('kind', 'dono')->count());
    }

    public function test_mesmo_telefone_com_e_sem_nono_digito_e_com_ddi(): void
    {
        $this->assertTrue(GestorWhatsApp::mesmoTelefone('5541996578036', '41996578036'));
        $this->assertTrue(GestorWhatsApp::mesmoTelefone('554196578036', '5541996578036'));
        $this->assertFalse(GestorWhatsApp::mesmoTelefone('5541996578036', '5511996578036'));
    }

    public function test_ordem_do_dono_fica_guardada_e_teto_maximo_so_muda_com_pedido_dele(): void
    {
        $cfg = MarketingAutopilot::atual();
        $fb = FacebookAds::make();
        $g = new Gestor($cfg, $fb, new Autopilot($cfg, $fb, new AutopilotBrain), new Avisos($cfg));

        $g->ordem('Só R$ 100 por dia até otimizar');
        $this->assertStringContainsString('1. ', $g->ordens());
        $this->assertSame('Só R$ 100 por dia até otimizar', $cfg->fresh()->owner_orders[0]['texto']);

        $this->assertStringStartsWith('RECUSADO', $g->tetoMaximo(300, 'quero escalar', peloDono: false));
        $this->assertSame(200.0, $cfg->fresh()->max_cap);

        $g->tetoMaximo(150, 'dono liberou', peloDono: true);
        $this->assertSame(150.0, $cfg->fresh()->max_cap);

        $g->revogarOrdem(1);
        $this->assertSame([], $cfg->fresh()->owner_orders);
    }
}
