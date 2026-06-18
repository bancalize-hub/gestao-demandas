<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            // Objetivo da IA quando o lead está nesta etapa (ex.: "conduzir para agendar reunião").
            $table->text('goal')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->dropColumn('goal');
        });
    }
};
