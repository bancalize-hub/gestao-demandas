<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->nullable()->unique();
            $table->string('tipo', 20); // BUG | FEATURE
            $table->string('titulo');
            $table->text('descricao');
            $table->string('modulo');
            $table->string('prioridade', 20); // BAIXA | MEDIA | ALTA | CRITICA
            $table->text('impacto_negocio')->nullable();
            $table->string('status', 20)->default('TRIAGEM');
            $table->double('ordem')->default(0);

            // Solicitante
            $table->string('solicitante_nome');
            $table->string('solicitante_empresa');
            $table->string('solicitante_email');
            $table->dateTime('data_solicitacao');

            // Campos condicionais — BUG
            $table->text('comportamento_atual')->nullable();
            $table->text('comportamento_esperado')->nullable();
            $table->text('passos_reproducao')->nullable();
            $table->string('ambiente_url')->nullable();
            $table->string('navegador')->nullable();
            $table->string('dispositivo')->nullable();
            $table->dateTime('data_hora_ocorrencia')->nullable();
            $table->string('frequencia', 20)->nullable(); // SEMPRE | AS_VEZES | UMA_VEZ

            // Campos condicionais — FEATURE
            $table->text('objetivo')->nullable();
            $table->text('regras_negocio')->nullable();
            $table->text('fluxo_desejado')->nullable();
            $table->text('criterios_aceitacao')->nullable();
            $table->date('prazo_desejado')->nullable();

            $table->timestamps();
            $table->index(['status', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
