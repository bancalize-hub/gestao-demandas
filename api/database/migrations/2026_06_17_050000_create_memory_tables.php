<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Base de conhecimento extraída das conversas.
        Schema::create('memory_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->default('faq');      // faq | preco | objecao | procedimento | fato
            $table->string('gatilho');                    // pergunta/tema que dispara
            $table->text('conteudo');                     // a resposta/conhecimento
            $table->string('keywords')->nullable();       // p/ recuperação por palavra-chave
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->timestamps();
        });

        // Perfil de voz (singleton).
        Schema::create('style_profiles', function (Blueprint $table) {
            $table->id();
            $table->longText('summary')->nullable();
            $table->timestamps();
        });
        DB::table('style_profiles')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // Exemplos de mensagens suas (few-shot).
        Schema::create('style_samples', function (Blueprint $table) {
            $table->id();
            $table->text('text');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_chunks');
        Schema::dropIfExists('style_profiles');
        Schema::dropIfExists('style_samples');
    }
};
