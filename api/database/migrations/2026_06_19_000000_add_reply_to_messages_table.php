<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Responder/citar: wa_id da mensagem citada + um trecho dela (para exibir o balão de citação).
            $table->string('reply_to')->nullable()->after('wa_id');
            $table->string('reply_excerpt', 200)->nullable()->after('reply_to');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['reply_to', 'reply_excerpt']);
        });
    }
};
