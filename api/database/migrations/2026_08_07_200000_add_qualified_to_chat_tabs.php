<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filtro de triagem na tab do chat, além das etiquetas.
 *
 * A tab já recortava por ETAPA do funil, mas etapa não diz se o lead presta: "Novo lead"
 * junta quem pediu preço e quem clicou no anúncio e sumiu. Quem trabalha a fila quer ver
 * só quem vale o tempo, sem sair da etapa.
 *
 * É ARRAY, e não uma coluna de valor único, porque as combinações úteis são de mais de um
 * estado ao mesmo tempo — "qualificado + ainda sem triagem" é a fila real de quem prospecta,
 * já que metade dos leads de anúncio nunca falou nada e a IA se abstém neles de propósito.
 *
 * Vazio/NULL = sem filtro (mostra todos), que é como as tabs existentes já se comportam —
 * por isso nasce nulo e ninguém precisa reconfigurar nada.
 *
 * Valores: '1' qualificado, '0' desqualificado, 'sem' ainda não triado. É o mesmo
 * vocabulário do critério das listas automáticas — duas telas que falam de triagem com
 * palavras diferentes viram bug de interpretação na primeira vez que alguém compara as duas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_tabs', function (Blueprint $table) {
            $table->json('qualified')->nullable()->after('stages');
        });
    }

    public function down(): void
    {
        Schema::table('chat_tabs', function (Blueprint $table) {
            $table->dropColumn('qualified');
        });
    }
};
