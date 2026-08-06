<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qualificação do lead — o filtro que separa quem pode comprar de quem só apareceu.
 *
 * NULO É UM ESTADO DE VERDADE: significa "ainda não olhei", e é diferente de
 * desqualificado. Sem essa distinção o painel contaria como lixo todo lead que ninguém
 * teve tempo de ler, e o custo por lead qualificado ficaria pessimista na mesma medida
 * em que a equipe estivesse atrasada na triagem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('qualified')->nullable()->after('segmento');
            $table->string('qualified_reason')->nullable()->after('qualified');
            // Quem decidiu. A triagem da IA é um palpite adiantado; a de quem atende é
            // a verdade. Sem esta marca o tick reescreveria por cima do julgamento
            // humano no minuto seguinte, e ninguém confiaria no botão de novo.
            $table->boolean('qualified_auto')->default(false)->after('qualified_reason');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['qualified', 'qualified_reason']);
        });
    }
};
