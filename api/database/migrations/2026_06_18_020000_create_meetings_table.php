<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reuniões agendadas a partir de uma conversa (auto-atendimento ou botão "Agendar").
        // Guardamos o suficiente para LEMBRAR o cliente pelo WhatsApp X minutos antes de começar.
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone')->nullable();              // número do cliente (só dígitos) — destino do lembrete
            $table->string('title')->nullable();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->nullable();
            $table->string('meet_link')->nullable();
            $table->string('google_event_id')->nullable();
            $table->unsignedSmallInteger('reminder_lead_minutes')->default(60); // quanto antes lembrar
            $table->dateTime('reminder_sent_at')->nullable();  // null = ainda não lembrou
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
