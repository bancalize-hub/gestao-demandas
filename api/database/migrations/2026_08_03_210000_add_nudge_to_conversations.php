<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Retomada ativa: quantas mensagens de retomada a IA já mandou NESTA rodada de
            // silêncio (zera quando o lead volta a responder) e quando foi a última delas.
            $table->unsignedTinyInteger('nudge_count')->default(0)->after('auto_reply_due_at');
            $table->timestamp('nudge_last_at')->nullable()->after('nudge_count');
        });

        Schema::table('companies', function (Blueprint $table) {
            // Chave geral da retomada ativa da empresa (a IA só busca leads se estiver ligada).
            $table->boolean('nudge_enabled')->default(true)->after('auto_reply_new_leads');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['nudge_count', 'nudge_last_at']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('nudge_enabled');
        });
    }
};
