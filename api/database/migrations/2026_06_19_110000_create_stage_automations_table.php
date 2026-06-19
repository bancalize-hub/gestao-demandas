<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Playbook" por etapa do funil: ao ENTRAR numa etapa, a conversa dispara uma
 * sequência de mensagens automáticas (texto/PDF) com atraso configurável.
 * Uma automação por etapa nesta fase (stage_key), mas o modelo já comporta mais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_automations', function (Blueprint $table) {
            $table->id();
            $table->string('stage_key')->index();   // chave estável da etapa (stages.key)
            $table->string('name');                  // rótulo do playbook (ex.: "Pós-reunião")
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_automations');
    }
};
