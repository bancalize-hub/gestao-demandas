<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Contatos de uma campanha (vindos do CSV) e o estado de cada disparo. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->index();
            $table->string('name')->nullable();
            $table->string('phone');                 // só dígitos (DDI+DDD+número)
            $table->json('vars')->nullable();        // colunas extras do CSV (p/ personalização da IA)
            $table->string('status')->default('pending'); // pending | sent | failed | replied | skipped
            $table->text('message_text')->nullable();// mensagem efetivamente enviada
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error')->nullable();
            $table->foreignId('conversation_id')->nullable(); // conversa criada no CRM
            $table->string('wa_id')->nullable();     // id da mensagem na Evolution (casa eco/recibo)
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_contacts');
    }
};
