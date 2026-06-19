<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disparo de UM passo para UMA conversa. O unique (conversation_id, step_id) garante
 * que cada passo roda no máximo uma vez por conversa — re-entrar na etapa não re-dispara.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->index();
            $table->foreignId('step_id')->index();
            $table->foreignId('automation_id')->index();
            $table->string('status')->default('pending'); // pending | sent | failed | skipped
            $table->timestamp('run_at')->index();         // quando o passo deve disparar
            $table->timestamp('sent_at')->nullable();
            $table->string('wa_id')->nullable();          // id da mensagem na Evolution
            $table->string('error')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'step_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_automation_runs');
    }
};
