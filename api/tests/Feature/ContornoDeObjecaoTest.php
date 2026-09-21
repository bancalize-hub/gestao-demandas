<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\MemoryChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * O contorno de objeção (meetings:contornos).
 *
 * Existe porque o aprendizado das calls morria no relatório: a objeção era extraída,
 * classificada — e a IA que atende no WhatsApp nunca via nada disso.
 */
class ContornoDeObjecaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude.oauth_token' => 'sk-ant-oat01-teste']);
    }

    /** @param  list<array<string, mixed>>  $objecoes */
    private function callDeVenda(array $objecoes, string $etapa = 'negociacao'): Meeting
    {
        $co = Company::create(['name' => 'Contorno', 'slug' => 'contorno-'.Str::random(5), 'is_active' => true]);

        $conv = Conversation::create([
            'name' => 'Lead', 'slug' => 'wa-5541999999999', 'initials' => 'L',
            'color' => '#888', 'stage' => $etapa, 'company_id' => $co->id,
        ]);

        return Meeting::create([
            'company_id' => $co->id,
            'conversation_id' => $conv->id,
            'title' => 'Call com o lead',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addMinutes(40),
            'transcript' => str_repeat('Maysa Lima: a entrada é de vinte mil. Lead: está caro. ', 100),
            'call_review' => ['nota_conducao' => 8, 'objecoes' => $objecoes],
        ]);
    }

    private function respostasDaIa(string ...$saidas): void
    {
        $seq = Process::sequence();
        foreach ($saidas as $s) {
            $seq->push(Process::result(output: $s));
        }
        Process::fake(['*' => $seq]);
    }

    public function test_a_resposta_da_call_vira_conteudo_para_a_ia_do_whatsapp(): void
    {
        $m = $this->callDeVenda([
            ['objecao' => 'achou o setup caro', 'respondida' => true, 'categoria' => 'preco_entrada'],
        ]);

        $this->respostasDaIa(
            '{"0": {"resposta": "O setup é uma vez só e substitui o time que você teria que montar", "funcionou": "sim"}}',
            '{"gatilho": "acha o setup caro", "conteudo": "Lembre que o setup é uma vez só.\nCompare com o custo de montar o time.", "keywords": "caro, setup, entrada, valor"}',
        );

        $this->artisan('meetings:contornos --min-casos=1 --desde=2020-01-01')->assertSuccessful();

        $chunk = MemoryChunk::withoutGlobalScopes()->where('origem', 'call:preco_entrada')->first();

        $this->assertNotNull($chunk, 'o contorno não virou conhecimento');
        $this->assertSame('objecao', $chunk->kind);
        $this->assertSame($m->company_id, $chunk->company_id);
        $this->assertNull($chunk->chat_tab_id, 'objeção vale para todos os times');
        $this->assertStringContainsString('setup é uma vez só', $chunk->conteudo);

        // A resposta lida fica gravada na revisão — a 2ª rodada não relê a transcrição.
        $this->assertSame('sim', $m->fresh()->call_review['objecoes'][0]['funcionou']);
    }

    public function test_a_segunda_rodada_reescreve_o_mesmo_conteudo_em_vez_de_duplicar(): void
    {
        $this->callDeVenda([
            ['objecao' => 'achou o setup caro', 'respondida' => true, 'categoria' => 'preco_entrada'],
        ]);

        $this->respostasDaIa(
            '{"0": {"resposta": "O setup é uma vez só", "funcionou": "sim"}}',
            '{"gatilho": "setup caro", "conteudo": "primeira versão", "keywords": "caro"}',
            // Na 2ª rodada a leitura da transcrição não acontece de novo (já tem resposta),
            // então a próxima saída da IA é direto o conteúdo.
            '{"gatilho": "setup caro", "conteudo": "versão revista", "keywords": "caro, entrada"}',
        );

        $this->artisan('meetings:contornos --min-casos=1 --desde=2020-01-01')->assertSuccessful();
        $this->artisan('meetings:contornos --min-casos=1 --desde=2020-01-01')->assertSuccessful();

        $chunks = MemoryChunk::withoutGlobalScopes()->where('origem', 'call:preco_entrada')->get();

        $this->assertCount(1, $chunks, 'cada rodada estava criando uma cópia do mesmo contorno');
        $this->assertSame('versão revista', $chunks->first()->conteudo);
    }

    public function test_objecao_que_ninguem_soube_responder_nao_vira_conteudo_inventado(): void
    {
        $this->callDeVenda([
            ['objecao' => 'quer saber de quem é a titularidade da conta', 'respondida' => false,
                'categoria' => 'titularidade_conta'],
        ]);

        // Só UMA saída: se o comando tentasse gerar o contorno assim mesmo, a 2ª chamada
        // ficaria sem resposta na fila e o teste quebraria.
        $this->respostasDaIa('{"0": {"resposta": "", "funcionou": "nao_respondida"}}');

        $this->artisan('meetings:contornos --min-casos=1 --desde=2020-01-01')
            ->expectsOutputToContain('BURACO')
            ->assertSuccessful();

        $this->assertSame(0, MemoryChunk::withoutGlobalScopes()->count());
    }
}
