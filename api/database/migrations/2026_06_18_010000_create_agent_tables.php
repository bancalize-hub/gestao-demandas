<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sessão de chat com o agente (cada sessão = um contexto contínuo do Claude Code).
        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('title')->default('Nova sessão');
            $table->string('cwd')->default('/var/www/gestao');
            $table->string('claude_session_id')->nullable(); // p/ --resume (mantém contexto)
            $table->timestamps();
        });

        // Histórico/auditoria das mensagens (quem pediu o quê, o que o agente respondeu).
        Schema::create('agent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_session_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // user | assistant
            $table->longText('content')->nullable();
            $table->timestamps();
        });

        // Execução: cada mensagem do usuário vira um job que o worker (gestao-agent) roda.
        Schema::create('agent_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_session_id')->constrained()->cascadeOnDelete();
            $table->longText('prompt');
            $table->longText('output')->nullable();   // saída acumulada (streaming via polling)
            $table->string('status')->default('pending'); // pending|running|done|error|canceled
            $table->boolean('cancel_requested')->default(false); // botão de matar
            $table->integer('pid')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_jobs');
        Schema::dropIfExists('agent_messages');
        Schema::dropIfExists('agent_sessions');
    }
};
