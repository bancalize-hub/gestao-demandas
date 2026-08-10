<?php

namespace Tests\Feature;

use App\Models\ChatTab;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Stage;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A LIXEIRA do chat: "excluir" nunca apagou nada — a conversa só ganha `hidden_at` e sai da
 * tela. A tab marcada como lixeira é o caminho de volta, que antes só existia nos poucos
 * segundos do botão Desfazer.
 */
class ConversasExcluidasTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        $co = Company::create(['name' => 'Empresa X', 'slug' => 'empresa-x', 'is_active' => true]);
        $user = User::create(['name' => 'Dono', 'email' => 'x@x.com', 'password' => bcrypt('x'), 'company_id' => $co->id]);
        $user->is_admin = true;
        $user->save();
        app(Tenancy::class)->run($co->id, fn () => Stage::create([
            'key' => 'novo', 'name' => 'Novo', 'color' => '#8696a0', 'position' => 0,
        ]));

        return $user;
    }

    private function conversa(User $user, string $slug): Conversation
    {
        return app(Tenancy::class)->run($user->company_id, fn () => Conversation::create([
            'slug' => $slug, 'name' => ucfirst($slug), 'initials' => 'X', 'color' => '#8696a0',
            'qualified' => true, 'stage' => 'novo',
        ]));
    }

    public function test_excluir_tira_da_lista_sem_apagar_e_a_lixeira_devolve(): void
    {
        $user = $this->makeOwner();
        $this->conversa($user, 'ativa');
        $this->conversa($user, 'excluida');

        Sanctum::actingAs($user);
        $this->deleteJson('/api/conversations/excluida')->assertOk();

        // Sumiu da lista, mas a linha continua no banco.
        $this->getJson('/api/conversations')->assertOk()->assertJsonCount(1)
            ->assertJsonFragment(['slug' => 'ativa'])
            ->assertJsonMissing(['slug' => 'excluida']);
        $this->assertDatabaseHas('conversations', ['slug' => 'excluida']);

        // A lixeira mostra SÓ ela — misturar com as ativas tiraria a informação que importa.
        $this->getJson('/api/conversations?excluidas=1')->assertOk()->assertJsonCount(1)
            ->assertJsonFragment(['slug' => 'excluida']);
    }

    public function test_restaurar_traz_a_conversa_de_volta(): void
    {
        $user = $this->makeOwner();
        $this->conversa($user, 'excluida');

        Sanctum::actingAs($user);
        $this->deleteJson('/api/conversations/excluida')->assertOk();
        $this->postJson('/api/conversations/excluida/restore')->assertOk()
            ->assertJsonFragment(['slug' => 'excluida']);

        $this->getJson('/api/conversations')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/conversations?excluidas=1')->assertOk()->assertJsonCount(0);
    }

    /** O patch de tempo real precisa achar a conversa excluída (a lixeira está mostrando ela). */
    public function test_linha_unica_traz_a_excluida(): void
    {
        $user = $this->makeOwner();
        $this->conversa($user, 'excluida');

        Sanctum::actingAs($user);
        $this->deleteJson('/api/conversations/excluida')->assertOk();
        $this->getJson('/api/conversations/excluida')->assertOk()
            ->assertJsonFragment(['slug' => 'excluida']);
    }

    public function test_tab_guarda_a_marca_de_lixeira(): void
    {
        $user = $this->makeOwner();
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/chat-tabs', ['name' => 'Lixeira', 'stages' => [], 'show_hidden' => true])
            ->assertCreated()->json('id');

        $this->assertTrue((bool) ChatTab::withoutGlobalScopes()->find($id)->show_hidden);
    }

    /** Lixeira é arquivo morto, não um time: não pode ditar o objetivo que a IA persegue. */
    public function test_lixeira_nao_vira_time_da_etapa(): void
    {
        $user = $this->makeOwner();
        app(Tenancy::class)->run($user->company_id, function () {
            ChatTab::create(['name' => 'Lixeira', 'stages' => ['novo'], 'show_hidden' => true, 'position' => 0]);
            ChatTab::create(['name' => 'SDR', 'stages' => ['novo'], 'position' => 1]);

            $this->assertSame('SDR', ChatTab::forStage('novo')?->name);
        });
    }
}
