<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\Stage;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lead REPROVADO na triagem some da tela como conversa excluída: nada é apagado, ele
 * continua no banco (e sendo atendido/reavaliado pelos ticks) e volta à lista sozinho
 * assim que o veredito mudar.
 */
class TriagemVisibilidadeTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        $co = Company::create(['name' => 'Empresa T', 'slug' => 'empresa-t', 'is_active' => true]);
        $user = User::create(['name' => 'Dono', 'email' => 't@t.com', 'password' => bcrypt('x'), 'company_id' => $co->id]);
        $user->is_admin = true;
        $user->save();
        app(Tenancy::class)->run($co->id, function () {
            Stage::create(['key' => 'novo', 'name' => 'Novo', 'color' => '#8696a0', 'position' => 0]);
            Stage::create(['key' => 'proposta', 'name' => 'Proposta', 'color' => '#8696a0', 'position' => 1]);
        });

        return $user;
    }

    private function conversa(User $user, string $slug, ?bool $qualified, string $stage = 'novo'): Conversation
    {
        return app(Tenancy::class)->run($user->company_id, fn () => Conversation::create([
            'slug' => $slug, 'name' => ucfirst($slug), 'initials' => 'X', 'color' => '#8696a0',
            'qualified' => $qualified, 'stage' => $stage,
        ]));
    }

    public function test_desqualificado_sai_da_lista_e_os_outros_ficam(): void
    {
        $user = $this->makeOwner();
        $this->conversa($user, 'qualificado', true);
        $this->conversa($user, 'sem-triagem', null);
        $this->conversa($user, 'reprovado', false);

        Sanctum::actingAs($user);
        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['slug' => 'qualificado'])
            ->assertJsonFragment(['slug' => 'sem-triagem'])
            ->assertJsonMissing(['slug' => 'reprovado']);
    }

    public function test_pedido_explicito_traz_so_os_reprovados(): void
    {
        $user = $this->makeOwner();
        $this->conversa($user, 'qualificado', true);
        $this->conversa($user, 'reprovado', false);

        Sanctum::actingAs($user);
        $this->getJson('/api/conversations?desqualificados=1')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['slug' => 'reprovado']);
    }

    /**
     * As duas exceções: reprovar é quase sempre palpite da IA, e quem já andou no funil ou
     * ocupou a agenda de alguém é caro demais para sumir em silêncio se o palpite errou.
     */
    public function test_reprovado_fora_da_primeira_etapa_ou_com_reuniao_continua_na_lista(): void
    {
        $user = $this->makeOwner();
        $this->conversa($user, 'reprovado-novo', false);
        $this->conversa($user, 'reprovado-em-proposta', false, 'proposta');
        $comReuniao = $this->conversa($user, 'reprovado-com-reuniao', false);
        app(Tenancy::class)->run($user->company_id, fn () => Meeting::create([
            'conversation_id' => $comReuniao->id, 'title' => 'Call', 'starts_at' => now()->addDay(),
        ]));

        Sanctum::actingAs($user);
        $res = $this->getJson('/api/conversations')->assertOk()->assertJsonCount(2);
        $res->assertJsonFragment(['slug' => 'reprovado-em-proposta'])
            ->assertJsonFragment(['slug' => 'reprovado-com-reuniao'])
            ->assertJsonMissing(['slug' => 'reprovado-novo']);

        // A tela precisa da conta pronta: ela não tem como refazê-la (reunião não vem na linha).
        $linhas = collect($res->json())->keyBy('slug');
        $this->assertSame(0, (int) $linhas['reprovado-em-proposta']['triagem_oculta']);
        $this->assertSame(0, (int) $linhas['reprovado-com-reuniao']['triagem_oculta']);
        $this->assertSame(1, (int) $this->getJson('/api/conversations/reprovado-novo')->json('triagem_oculta'));
    }

    /** O patch de tempo real precisa da linha mesmo reprovada — quem esconde é a tela. */
    public function test_linha_unica_traz_o_reprovado(): void
    {
        $user = $this->makeOwner();
        $this->conversa($user, 'reprovado', false);

        Sanctum::actingAs($user);
        $this->getJson('/api/conversations/reprovado')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'reprovado']);
    }

    public function test_requalificar_faz_o_lead_voltar_para_a_lista(): void
    {
        $user = $this->makeOwner();
        $this->conversa($user, 'reprovado', false);

        Sanctum::actingAs($user);
        $this->patchJson('/api/conversations/reprovado', ['qualified' => true])->assertOk();

        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['slug' => 'reprovado']);
    }

    /** Esconder pela triagem não apaga: o histórico continua todo no banco. */
    public function test_nada_e_apagado_do_banco(): void
    {
        $user = $this->makeOwner();
        $this->conversa($user, 'reprovado', false);

        $this->assertDatabaseHas('conversations', ['slug' => 'reprovado', 'qualified' => false]);
    }

    /** Conversa excluída (hidden_at) continua fora até das duas listagens. */
    public function test_excluida_nao_reaparece_pela_lista_de_reprovados(): void
    {
        $user = $this->makeOwner();
        $conv = $this->conversa($user, 'reprovado', false);
        app(Tenancy::class)->run($user->company_id, fn () => $conv->update(['hidden_at' => now()]));

        Sanctum::actingAs($user);
        $this->getJson('/api/conversations')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/conversations?desqualificados=1')->assertOk()->assertJsonCount(0);
    }
}
