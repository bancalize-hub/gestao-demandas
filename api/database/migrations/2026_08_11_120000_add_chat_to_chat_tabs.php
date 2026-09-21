<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_tabs', function (Blueprint $table) {
            // Nº do chat (balão do menu lateral) dono da tab. NULL = tab antiga, aparece
            // em todos os chats. É só visibilidade de UI: a lógica de time da IA
            // (forStage/objetivo) ignora esta coluna de propósito.
            $table->unsignedTinyInteger('chat')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('chat_tabs', function (Blueprint $table) {
            $table->dropColumn('chat');
        });
    }
};
