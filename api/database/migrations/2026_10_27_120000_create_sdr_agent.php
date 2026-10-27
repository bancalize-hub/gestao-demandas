<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agente SDR: vigia as conversas e mantém o lead aquecido até ele COMPARECER à call.
 *
 * - `sdr_agents`: uma linha por empresa — liga/desliga, o ritmo que o próprio agente marca e
 *   os limites anti-spam que ele NÃO pode passar (a conta já foi travada pela Meta por
 *   insistência, em 14/08/2026).
 * - `conversations.sdr_*`: o que o agente combinou com ele mesmo sobre cada lead (voltar em X
 *   horas, deixar quieto até tal dia).
 *
 * Os turnos reaproveitam `marketing_gestor_sessions` (agente = 'sdr'): é o mesmo motor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sdr_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->unique();
            $table->boolean('enabled')->default(false);
            // Limites anti-spam (a ferramenta recusa o que passar deles).
            $table->unsignedTinyInteger('max_unanswered')->default(2);   // msgs do SDR seguidas sem resposta
            $table->unsignedSmallInteger('min_hours_between')->default(20); // entre duas do SDR p/ o mesmo lead sem resposta
            $table->unsignedSmallInteger('daily_cap')->default(25);       // msgs do SDR por dia na empresa
            $table->unsignedSmallInteger('per_turn_cap')->default(12);    // msgs do SDR por turno
            $table->text('instructions')->nullable();
            $table->json('learnings')->nullable();
            $table->timestamp('run_requested_at')->nullable();
            $table->string('run_requested_mode', 16)->nullable();
            $table->timestamp('last_review_at')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->text('last_analysis')->nullable();
            $table->string('last_result')->nullable();
            $table->timestamps();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('sdr_follow_at')->nullable()->index();
            $table->string('sdr_follow_note', 500)->nullable();
            $table->timestamp('sdr_paused_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sdr_agents');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['sdr_follow_at', 'sdr_follow_note', 'sdr_paused_until']);
        });
    }
};
