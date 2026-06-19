<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Follow-up de lead = tarefa (type='followup') vinculada a uma conversa.
            // Reaproveita o kanban e o sync com Google Calendar já existentes.
            $table->foreignId('conversation_id')->nullable()->after('id')->constrained()->nullOnDelete();
            // No vencimento, o tick gera um rascunho de mensagem (IA); o vendedor é quem envia.
            $table->text('ai_draft')->nullable()->after('google_user_id');
            $table->dateTime('ai_draft_at')->nullable()->after('ai_draft');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropColumn(['ai_draft', 'ai_draft_at']);
        });
    }
};
