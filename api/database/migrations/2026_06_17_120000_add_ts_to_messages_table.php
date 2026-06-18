<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Timestamp (unix) da mensagem no WhatsApp — usado para ordenar de forma
            // estável após merges não-destrutivos (webhook + import).
            $table->unsignedInteger('ts')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('ts');
        });
    }
};
