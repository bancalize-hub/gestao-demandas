<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segundo lembrete de reunião ("está começando"), 15 min antes.
 *
 * Precisa de carimbo próprio: `reminder_sent_at` é a trava do lembrete de 1h e, se os dois
 * dividissem a mesma coluna, o tick reenviaria em loop ou pularia um dos avisos.
 *
 * Reunião PASSADA nasce carimbada, igual ao `reminder_sent_at` do calendar-sync: importar
 * histórico não pode disparar aviso de reunião que já aconteceu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dateTime('reminder2_sent_at')->nullable()->after('reminder_sent_at');
        });

        // Backfill: tudo que já passou não deve gerar o aviso de 15 min.
        Schema::getConnection()->table('meetings')
            ->whereNull('reminder2_sent_at')
            ->where('starts_at', '<', now())
            ->update(['reminder2_sent_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('reminder2_sent_at');
        });
    }
};
