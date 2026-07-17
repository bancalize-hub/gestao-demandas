<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índice composto que cobre o latestOfMany('ts') da lista de conversas, o
     * withMin(started_ts), a ordenação da thread e o filtro do auto-reply tick —
     * o índice solto de company_id tinha cardinalidade 1 e forçava varreduras.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'ts', 'id'], 'messages_conv_ts_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_conv_ts_id_index');
        });
    }
};
