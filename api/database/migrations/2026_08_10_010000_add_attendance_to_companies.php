<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horário de atendimento da empresa: {days: [1..7 ISO], start: 'HH:MM', end: 'HH:MM'}.
 *
 * NULL = nunca configurado → vale o padrão (seg–sex, 09:00–19:00, ver Attendance::PADRAO).
 * É UMA janela para a empresa inteira, não por dia da semana: o uso real aqui é "quando a
 * IA pode abordar alguém por conta própria", e essa pergunta não muda de terça pra quinta.
 *
 * Quem consome: retomada ativa (NudgeTick), oferta de horário de reunião
 * (MeetingScheduler/AiReplyService) e o padrão de janela de campanha nova.
 * A resposta imediata a quem ESCREVE fica fora de propósito — segurar a resposta de um
 * lead vivo até as 9h só esfria a conversa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('attendance')->nullable()->after('qualify_criteria');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('attendance');
        });
    }
};
