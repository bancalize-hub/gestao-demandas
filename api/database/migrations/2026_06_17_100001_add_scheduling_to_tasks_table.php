<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Agendamento opcional: quando preenchido, a tarefa vira evento no Google Calendar.
            // O campo 'due' (string livre) continua existindo para o kanban.
            $table->dateTime('starts_at')->nullable()->after('due');
            $table->dateTime('ends_at')->nullable()->after('starts_at');
            // ID do evento espelhado no Google (mapeia Task -> Event para update/delete).
            $table->string('google_event_id')->nullable()->after('ends_at');
            // De qual usuário é o calendário onde o evento foi criado (para refresh do token no update/delete).
            $table->foreignId('google_user_id')->nullable()->after('google_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at', 'google_event_id', 'google_user_id']);
        });
    }
};
