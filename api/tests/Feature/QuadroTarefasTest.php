<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Models\TaskList;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O quadro de tarefas (/tarefas): listas da empresa, cartões só do que entra à mão,
 * ordem dentro da lista, arquivo, etiquetas, responsáveis e o cartão por dentro.
 */
class QuadroTarefasTest extends TestCase
{
    use RefreshDatabase;

    private function dono(string $empresa, string $email): User
    {
        $co = Company::create(['name' => $empresa, 'slug' => Str::slug($empresa), 'is_active' => true]);
        $user = User::create(['name' => 'Dono '.$empresa, 'email' => $email, 'password' => bcrypt('x'), 'company_id' => $co->id]);
        $user->is_admin = true;
        $user->save();

        return $user;
    }

    /** Roda um trecho dentro da empresa do usuário (como o middleware set.tenant faz). */
    private function comoEmpresa(User $user, callable $fn)
    {
        return app(Tenancy::class)->run($user->company_id, $fn);
    }

    public function test_quadro_nasce_com_listas_e_etiquetas_padrao(): void
    {
        $dono = $this->dono('Acme', 'dono@acme.com');
        Sanctum::actingAs($dono);

        $res = $this->getJson('/api/board')->assertOk();

        $this->assertCount(4, $res->json('lists'));
        $this->assertSame('A fazer', $res->json('lists.0.name'));
        $this->assertCount(6, $res->json('labels'), 'paleta inicial de etiquetas');
        $this->assertSame($dono->id, $res->json('members.0.id'));
        $this->assertSame([], $res->json('cards'));
    }

    public function test_quadro_ignora_tarefa_de_robo_e_cartao_arquivado(): void
    {
        $dono = $this->dono('Acme', 'dono@acme.com');
        Sanctum::actingAs($dono);
        $this->getJson('/api/board');   // semeia as listas

        $this->comoEmpresa($dono, function () {
            $lista = TaskList::first();
            Task::create(['title' => 'Cartão de gente', 'task_list_id' => $lista->id, 'priority' => 'media', 'column' => 'todo']);
            Task::create(['title' => 'Negócio parado há 3 dias', 'type' => 'followup', 'task_list_id' => $lista->id, 'priority' => 'alta', 'column' => 'todo']);
            Task::create(['title' => 'Arquivado', 'task_list_id' => $lista->id, 'priority' => 'media', 'column' => 'todo', 'archived_at' => now()]);
        });

        $cards = $this->getJson('/api/board')->assertOk()->json('cards');

        $this->assertCount(1, $cards);
        $this->assertSame('Cartão de gente', $cards[0]['title']);
    }

    public function test_cartao_novo_cai_na_primeira_lista_e_no_topo(): void
    {
        $dono = $this->dono('Acme', 'dono@acme.com');
        Sanctum::actingAs($dono);
        $primeira = $this->getJson('/api/board')->json('lists.0.id');

        $this->postJson('/api/tasks', ['title' => 'Primeiro'])->assertCreated();
        $segundo = $this->postJson('/api/tasks', ['title' => 'Segundo'])->assertCreated()->json();

        $this->assertSame($primeira, $segundo['task_list_id']);

        $cards = $this->getJson('/api/board')->json('cards');
        $this->assertSame(['Segundo', 'Primeiro'], array_column($cards, 'title'), 'o mais novo entra no topo');
    }

    public function test_mover_cartao_reordena_a_lista_de_destino(): void
    {
        $dono = $this->dono('Acme', 'dono@acme.com');
        Sanctum::actingAs($dono);
        $listas = $this->getJson('/api/board')->json('lists');
        [$aFazer, $andamento] = [$listas[0]['id'], $listas[1]['id']];

        $a = $this->postJson('/api/tasks', ['title' => 'A', 'task_list_id' => $andamento])->json('id');
        $b = $this->postJson('/api/tasks', ['title' => 'B', 'task_list_id' => $andamento])->json('id');
        $c = $this->postJson('/api/tasks', ['title' => 'C', 'task_list_id' => $aFazer])->json('id');

        // C sai de "A fazer" e entra no MEIO de "Em andamento" (B, C, A).
        $this->patchJson("/api/tasks/{$c}/move", ['task_list_id' => $andamento, 'index' => 1])->assertOk();

        $cards = collect($this->getJson('/api/board')->json('cards'))
            ->where('task_list_id', $andamento)->sortBy('position')->values();

        $this->assertSame(['B', 'C', 'A'], $cards->pluck('title')->all());
        $this->assertSame([0, 1, 2], $cards->pluck('position')->all(), 'posição reindexada, sem empate');
    }

    public function test_arquivar_e_restaurar_cartao(): void
    {
        $dono = $this->dono('Acme', 'dono@acme.com');
        Sanctum::actingAs($dono);
        $this->getJson('/api/board');
        $id = $this->postJson('/api/tasks', ['title' => 'Some e volta'])->json('id');

        $this->postJson("/api/tasks/{$id}/archive")->assertOk();
        $this->assertCount(0, $this->getJson('/api/board')->json('cards'));
        $this->assertCount(1, $this->getJson('/api/board/archived')->json('cards'));

        $this->postJson("/api/tasks/{$id}/restore")->assertOk();
        $this->assertCount(1, $this->getJson('/api/board')->json('cards'));
    }

    public function test_arquivar_lista_leva_os_cartoes_e_restaurar_devolve(): void
    {
        $dono = $this->dono('Acme', 'dono@acme.com');
        Sanctum::actingAs($dono);
        $listas = $this->getJson('/api/board')->json('lists');
        $lista = $listas[1]['id'];

        $this->postJson('/api/tasks', ['title' => 'Junto', 'task_list_id' => $lista]);
        $sozinho = $this->postJson('/api/tasks', ['title' => 'Arquivado antes', 'task_list_id' => $lista])->json('id');
        $this->postJson("/api/tasks/{$sozinho}/archive")->assertOk();

        $this->deleteJson("/api/board/lists/{$lista}")->assertOk();

        $quadro = $this->getJson('/api/board')->json();
        $this->assertCount(3, $quadro['lists'], 'a lista saiu do quadro');
        $this->assertCount(0, $quadro['cards']);

        $this->postJson("/api/board/lists/{$lista}/restore")->assertOk();

        $quadro = $this->getJson('/api/board')->json();
        $this->assertCount(4, $quadro['lists']);
        $this->assertSame(['Junto'], array_column($quadro['cards'], 'title'), 'o que já estava arquivado continua no arquivo');
    }

    public function test_etiqueta_responsavel_checklist_comentario_e_anexo_aparecem_no_cartao(): void
    {
        $dono = $this->dono('Acme', 'dono@acme.com');
        Sanctum::actingAs($dono);
        $quadro = $this->getJson('/api/board')->json();
        $id = $this->postJson('/api/tasks', ['title' => 'Cheio'])->json('id');
        $etiqueta = $quadro['labels'][0]['id'];

        $this->putJson("/api/tasks/{$id}/labels", ['label_ids' => [$etiqueta]])->assertOk();
        $this->putJson("/api/tasks/{$id}/members", ['user_ids' => [$dono->id]])->assertOk();
        $item = $this->postJson("/api/tasks/{$id}/checklist", ['text' => 'Ligar para o cliente'])->assertCreated()->json('id');
        $this->postJson("/api/tasks/{$id}/checklist", ['text' => 'Mandar o contrato'])->assertCreated();
        $this->patchJson("/api/tasks/{$id}/checklist/{$item}", ['done' => true])->assertOk();
        $this->postJson("/api/tasks/{$id}/comments", ['body' => 'Falei com ele hoje'])->assertCreated();
        $this->postJson("/api/tasks/{$id}/attachments", ['file' => UploadedFile::fake()->create('proposta.pdf', 12)])->assertCreated();

        $card = collect($this->getJson('/api/board')->json('cards'))->firstWhere('id', $id);
        $this->assertSame([$etiqueta], $card['label_ids']);
        $this->assertSame([['id' => $dono->id, 'name' => $dono->name]], $card['members']);
        $this->assertSame(2, $card['checklist_total']);
        $this->assertSame(1, $card['checklist_done']);
        $this->assertSame(1, $card['comments_count']);
        $this->assertSame(1, $card['attachments_count']);

        $detalhe = $this->getJson("/api/tasks/{$id}/card")->assertOk()->json();
        $this->assertCount(2, $detalhe['checklist']);
        $this->assertSame('Falei com ele hoje', $detalhe['comments'][0]['body']);
        $this->assertSame('proposta.pdf', $detalhe['attachments'][0]['name']);
    }

    public function test_quadro_de_uma_empresa_nao_encosta_no_da_outra(): void
    {
        $a = $this->dono('Acme', 'a@acme.com');
        $b = $this->dono('Beta', 'b@beta.com');

        Sanctum::actingAs($a);
        $this->getJson('/api/board');
        $cartaoA = $this->postJson('/api/tasks', ['title' => 'Segredo da Acme'])->json('id');
        $listaA = $this->getJson('/api/board')->json('lists.0.id');
        $etiquetaA = $this->getJson('/api/board')->json('labels.0.id');

        Sanctum::actingAs($b);
        $quadroB = $this->getJson('/api/board')->json();

        $this->assertSame([], $quadroB['cards'], 'Beta não vê cartão da Acme');
        $this->assertNotContains($listaA, array_column($quadroB['lists'], 'id'));

        // Nem pela URL: id de outra empresa não existe para quem está logado aqui.
        $this->getJson("/api/tasks/{$cartaoA}/card")->assertNotFound();
        $this->deleteJson("/api/board/lists/{$listaA}")->assertNotFound();
        $cartaoB = $this->postJson('/api/tasks', ['title' => 'Cartão da Beta'])->json('id');
        $this->putJson("/api/tasks/{$cartaoB}/labels", ['label_ids' => [$etiquetaA]])
            ->assertStatus(422);
    }

    public function test_lista_arquivada_nao_recebe_cartao(): void
    {
        $dono = $this->dono('Acme', 'dono@acme.com');
        Sanctum::actingAs($dono);
        $listas = $this->getJson('/api/board')->json('lists');
        $id = $this->postJson('/api/tasks', ['title' => 'Mover'])->json('id');

        $this->deleteJson("/api/board/lists/{$listas[3]['id']}")->assertOk();

        $this->patchJson("/api/tasks/{$id}/move", ['task_list_id' => $listas[3]['id'], 'index' => 0])
            ->assertStatus(422);
    }

    public function test_solicitacao_do_cliente_entra_na_primeira_lista_da_empresa_certa(): void
    {
        $dono = $this->dono('Acme', 'dono@acme.com');
        Sanctum::actingAs($dono);
        $primeira = $this->getJson('/api/board')->json('lists.0.id');

        // Portal público: sem login, identificando a empresa pelo slug.
        app('auth')->forgetGuards();
        $this->postJson('/api/solicitacoes', [
            'title' => 'Preciso de um ajuste',
            'description' => 'O relatório está saindo sem a coluna de imposto.',
            'client' => 'Fulano · Beta',
            'company_slug' => 'acme',
            'due' => 'Sem prazo',
            'type' => 'Bug',
        ])->assertCreated();

        Sanctum::actingAs($dono);
        $cards = $this->getJson('/api/board')->json('cards');
        $this->assertCount(1, $cards);
        $this->assertSame($primeira, $cards[0]['task_list_id']);
        $this->assertSame('Bug', $cards[0]['type']);
    }

    public function test_listas_e_etiquetas_sao_da_empresa_e_reordenam(): void
    {
        $dono = $this->dono('Acme', 'dono@acme.com');
        Sanctum::actingAs($dono);
        $this->getJson('/api/board');

        $nova = $this->postJson('/api/board/lists', ['name' => 'Esperando cliente', 'color' => '#a78bfa'])->assertCreated()->json();
        $this->patchJson("/api/board/lists/{$nova['id']}", ['name' => 'Com o cliente'])->assertOk();

        $ids = array_column($this->getJson('/api/board')->json('lists'), 'id');
        $this->postJson('/api/board/lists/reorder', ['ids' => array_reverse($ids)])->assertOk();

        $depois = array_column($this->getJson('/api/board')->json('lists'), 'id');
        $this->assertSame(array_reverse($ids), $depois);
        $this->assertSame('Com o cliente', $this->getJson('/api/board')->json('lists.0.name'));

        $etiqueta = $this->postJson('/api/board/labels', ['color' => '#ff6b6b', 'name' => 'Urgente'])->assertCreated()->json();
        $this->assertSame('Urgente', $etiqueta['name']);
        $this->deleteJson("/api/board/labels/{$etiqueta['id']}")->assertOk();
        $this->assertCount(6, $this->getJson('/api/board')->json('labels'));
    }
}
