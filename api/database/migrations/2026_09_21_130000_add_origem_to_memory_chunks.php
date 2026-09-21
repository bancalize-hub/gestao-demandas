<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de onde veio o conhecimento, para o que é gerado por máquina poder ser REESCRITO
 * no lugar em vez de duplicado.
 *
 * O conhecimento até aqui nascia de conversa ("memorizar") ou da mão do atendente, e nunca
 * era reescrito. O contorno de objeção vindo das calls é diferente: ele melhora a cada nova
 * call analisada. Sem uma chave estável, cada rodada do agendador criaria mais uma cópia do
 * mesmo contorno e a recuperação por palavra-chave passaria a devolver três versões do
 * mesmo texto para a IA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memory_chunks', function (Blueprint $table) {
            // ex.: "call:preco_entrada" — nulo em tudo que veio de gente.
            $table->string('origem')->nullable()->after('conversation_id');
            $table->index(['company_id', 'origem']);
        });
    }

    public function down(): void
    {
        Schema::table('memory_chunks', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'origem']);
            $table->dropColumn('origem');
        });
    }
};
