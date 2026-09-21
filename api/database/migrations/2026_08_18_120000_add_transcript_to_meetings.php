<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda a transcrição BRUTA da reunião (falas "Fulano: texto"), não só o resumo.
 *
 * O `summary` já existia, mas resumo serve para a ficha do lead — não dá para auditar
 * o que fez a call travar. Erro de condução aparece na fala literal: objeção que ficou
 * sem resposta, preço solto no fim, call que acaba sem próximo passo. O texto vem da
 * Meet API (mesma origem do resumo) e por isso não custa nada além da chamada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->longText('transcript')->nullable()->after('summary_source');
            $table->string('transcript_source', 16)->nullable()->after('transcript');
            $table->json('call_review')->nullable()->after('transcript_source');
            $table->dateTime('reviewed_at')->nullable()->after('call_review');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['transcript', 'transcript_source', 'call_review', 'reviewed_at']);
        });
    }
};
