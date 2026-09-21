<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Silêncio da IA enquanto um humano está conduzindo a conversa.
 *
 * Sem isto, os dois falam ao mesmo tempo: em 12/08/2026 a IA confirmou uma remarcação
 * no mesmo minuto em que a atendente pedia desculpas ao lead por um no-show nosso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dateTime('ai_paused_until')->nullable()->after('auto_reply_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('ai_paused_until');
        });
    }
};
