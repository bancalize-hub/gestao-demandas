<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IA por TIME (SDR / Closer / CS).
 *
 * As tabs do chat (chat_tabs) já agrupam as etapas do funil por time — é a
 * "tag" que a equipe usa no dia a dia. Passam a carregar também o OBJETIVO da
 * IA naquele time, e cada conhecimento da memória pode ficar restrito a um
 * time (chat_tab_id nulo = vale para todos, que é o comportamento de hoje).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_tabs', function (Blueprint $table) {
            $table->text('objetivo')->nullable()->after('stages');
        });

        Schema::table('memory_chunks', function (Blueprint $table) {
            $table->unsignedBigInteger('chat_tab_id')->nullable()->after('kind');
            $table->index('chat_tab_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_tabs', function (Blueprint $table) {
            $table->dropColumn('objetivo');
        });

        Schema::table('memory_chunks', function (Blueprint $table) {
            $table->dropIndex(['chat_tab_id']);
            $table->dropColumn('chat_tab_id');
        });
    }
};
