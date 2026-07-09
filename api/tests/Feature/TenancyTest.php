<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Stage;
use App\Models\User;
use App\Models\WaAccount;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Garante o isolamento multi-empresa: cada empresa só enxerga/afeta os próprios dados,
 * o cadastro self-service cria empresa+dono, e recursos de plataforma (/agente) são
 * exclusivos do super-admin.
 */
class TenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Marca o host de teste como stateful para que login/cadastro tenham sessão (Sanctum SPA).
        config(['sanctum.stateful' => ['localhost']]);
    }

    /** Cadastro via HTTP (exercita o endpoint /register e a sessão). */
    private function register(string $company, string $email): User
    {
        $res = $this->withHeader('Referer', 'http://localhost')->postJson('/api/register', [
            'company' => $company,
            'name' => 'Dono '.$company,
            'email' => $email,
            'password' => 'segredo123',
        ]);
        $res->assertCreated();

        return User::where('email', $email)->firstOrFail();
    }

    /** Cria empresa + dono + funil padrão direto (sem sessão), p/ testar escopo via token. */
    private function makeOwner(string $company, string $email): User
    {
        $co = Company::create(['name' => $company, 'slug' => \Illuminate\Support\Str::slug($company), 'is_active' => true]);
        $user = User::create(['name' => 'Dono', 'email' => $email, 'password' => bcrypt('x'), 'company_id' => $co->id]);
        $user->is_admin = true;
        $user->save();
        app(Tenancy::class)->run($co->id, function () {
            foreach (['novo', 'contato', 'proposta', 'negociacao', 'fechado'] as $i => $k) {
                Stage::create(['key' => $k, 'name' => ucfirst($k), 'color' => '#8696a0', 'position' => $i]);
            }
        });

        return $user;
    }

    public function test_cadastro_cria_empresa_dono_e_funil_padrao(): void
    {
        $user = $this->register('Padaria do Zé', 'ze@padaria.com');

        $this->assertNotNull($user->company_id);
        $this->assertTrue($user->is_admin, 'dono é admin da própria empresa');
        $this->assertFalse((bool) $user->is_super_admin, 'dono NÃO é super-admin da plataforma');

        $company = Company::find($user->company_id);
        $this->assertSame('padaria-do-ze', $company->slug);

        // Funil padrão semeado só para a nova empresa.
        $count = app(Tenancy::class)->run($user->company_id, fn () => Stage::count());
        $this->assertSame(5, $count);
    }

    public function test_duas_empresas_nao_veem_dados_uma_da_outra_via_api(): void
    {
        $a = $this->makeOwner('Empresa A', 'a@a.com');
        $b = $this->makeOwner('Empresa B', 'b@b.com');

        // Empresa A cria uma etapa própria via API autenticada.
        Sanctum::actingAs($a);
        $this->postJson('/api/stages', ['name' => 'Etapa Secreta A'])->assertCreated();

        // A enxerga suas 5 padrão + a nova = 6.
        Sanctum::actingAs($a);
        $this->getJson('/api/stages')->assertOk()->assertJsonCount(6);

        // B enxerga só as suas 5 padrão e NUNCA a "Etapa Secreta A".
        Sanctum::actingAs($b);
        $this->getJson('/api/stages')
            ->assertOk()
            ->assertJsonCount(5)
            ->assertJsonMissing(['name' => 'Etapa Secreta A']);
    }

    public function test_agente_e_exclusivo_do_super_admin(): void
    {
        $owner = $this->makeOwner('Empresa C', 'c@c.com'); // admin da empresa, não super-admin
        Sanctum::actingAs($owner);
        $this->getJson('/api/agent/sessions')->assertForbidden();

        // Vira super-admin → passa a acessar.
        $owner->forceFill(['is_super_admin' => true])->save();
        Sanctum::actingAs($owner->fresh());
        $this->getJson('/api/agent/sessions')->assertOk();
    }

    public function test_webhook_roteia_mensagem_para_a_empresa_dona_da_instancia(): void
    {
        config(['services.evolution.webhook_token' => 'tok']);

        $a = $this->makeOwner('Empresa D', 'd@d.com');
        $b = $this->makeOwner('Empresa E', 'e@e.com');

        // Cada empresa com seu número (instância) isolado.
        $instA = app(Tenancy::class)->run($a->company_id, fn () => WaAccount::create([
            'name' => 'Principal', 'instance' => 'cD-principal-xxxxx', 'role' => 'primary', 'is_active' => true,
        ])->instance);
        app(Tenancy::class)->run($b->company_id, fn () => WaAccount::create([
            'name' => 'Principal', 'instance' => 'cE-principal-yyyyy', 'role' => 'primary', 'is_active' => true,
        ]));

        // Chega uma mensagem na instância da Empresa D.
        $payload = [
            'event' => 'messages.upsert',
            'instance' => $instA,
            'data' => [
                'key' => ['remoteJid' => '5511999998888@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-D-1'],
                'pushName' => 'Cliente da D',
                'message' => ['conversation' => 'Olá, quero um orçamento'],
                'messageTimestamp' => time(),
            ],
        ];
        $this->postJson('/api/wpp/webhook?token=tok', $payload)->assertOk();

        // A conversa nasceu na Empresa D e é invisível para a Empresa E.
        $seenByD = app(Tenancy::class)->run($a->company_id, fn () => Conversation::where('slug', 'wa-5511999998888')->count());
        $seenByE = app(Tenancy::class)->run($b->company_id, fn () => Conversation::where('slug', 'wa-5511999998888')->count());
        $this->assertSame(1, $seenByD, 'conversa criada na empresa dona da instância');
        $this->assertSame(0, $seenByE, 'empresa E não enxerga a conversa da D');
    }

    public function test_webhook_ignora_instancia_desospitalizada_sem_vazar(): void
    {
        config(['services.evolution.webhook_token' => 'tok']);
        $this->makeOwner('Empresa F', 'f@f.com');

        $payload = [
            'event' => 'messages.upsert',
            'instance' => 'instancia-que-nao-existe',
            'data' => ['key' => ['remoteJid' => '5511000000000@s.whatsapp.net', 'fromMe' => false, 'id' => 'X'], 'message' => ['conversation' => 'oi']],
        ];
        $this->postJson('/api/wpp/webhook?token=tok', $payload)
            ->assertOk()
            ->assertJson(['ignored' => 'unknown-instance']);

        // Nada foi criado em nenhum lugar.
        $this->assertSame(0, Conversation::withoutGlobalScopes()->count());
    }
}
