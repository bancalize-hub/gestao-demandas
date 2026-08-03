<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materiais que a IA pode enviar ao lead (apresentação em PDF, tabela, contrato…).
 *
 * A ideia substitui a automação por etapa: em vez de "ao entrar na etapa X, mande o
 * PDF Y", a IA recebe a lista com o `quando` de cada material e decide na hora se
 * aquele arquivo ajuda a conversa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            // Time dono do material (chat_tabs): null = qualquer um pode enviar.
            $table->unsignedBigInteger('chat_tab_id')->nullable()->index();
            $table->string('name');                       // como a IA se refere a ele
            $table->text('quando')->nullable();           // em que situação enviar
            $table->string('path');                       // storage/app/...
            $table->string('filename');
            $table->string('mime', 191);
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
