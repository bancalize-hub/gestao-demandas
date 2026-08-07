<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memória de marketing: o que já se aprendeu sobre os anúncios desta empresa.
 *
 * POR QUE NÃO REUSAR `memory_chunks`: aquela tabela é a base de conhecimento da IA que
 * ATENDE o lead. "O Criativo 3 traz quem quer empréstimo" é verdade sobre o anúncio, não
 * sobre o produto — se entrasse lá, a IA passaria a dizer isso para o cliente.
 *
 * POR QUE PERSISTIR EM VEZ DE REDESCOBRIR: toda análise de campanha refaz o mesmo caminho
 * (puxar gasto, cruzar com o CRM, achar o criativo ruim) e joga a conclusão fora no fim da
 * conversa. Na análise de 06/08/2026 metade do tempo foi para reconstruir coisas já sabidas
 * dias antes — e o achado que mais valia (anúncio que diz o preço filtra antes do clique)
 * só existia na cabeça de quem estava lá.
 *
 * `periodo` guarda de quando é a leitura: aprendizado de marketing vence. Um criativo que
 * era o melhor em agosto pode ter fadigado em outubro, e uma memória sem data seria
 * repetida como se ainda valesse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            // criativo | publico | orcamento | metrica | aprendizado
            $table->string('categoria', 32)->default('aprendizado');
            $table->string('titulo');
            $table->text('conteudo');
            /** De quando é a leitura (ex.: "04–06/08/2026"), em texto livre. */
            $table->string('periodo', 64)->nullable();
            /**
             * Quanto se pode confiar: `medido` saiu de número apurado, `hipotese` é
             * suspeita ainda não confirmada. Sem esta marca, um palpite escrito com
             * confiança vira fato três semanas depois.
             */
            $table->string('confianca', 16)->default('medido');
            $table->boolean('fixado')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'categoria']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_memories');
    }
};
