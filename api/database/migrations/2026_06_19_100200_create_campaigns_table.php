<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Campanhas de prospecção ativa / disparo em massa (mensagem gerada pela IA). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('wa_account_id')->nullable()->index(); // número remetente (outreach)
            $table->string('status')->default('draft');              // draft | running | paused | done
            $table->text('objective')->nullable();                   // briefing p/ a IA gerar a abordagem
            // Anti-ban conservador (intervalos aleatórios + teto + janela de horário).
            $table->unsignedInteger('min_gap_s')->default(60);
            $table->unsignedInteger('max_gap_s')->default(180);
            $table->unsignedInteger('daily_cap')->default(40);
            $table->string('window_start', 5)->default('09:00');
            $table->string('window_end', 5)->default('18:00');
            // Contadores (denormalizados p/ a tela de acompanhamento).
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('replied')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
