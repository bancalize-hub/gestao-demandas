<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Descrição completa da solicitação (o cliente escreve no portal). Antes só sobrava
            // o título truncado em 70 chars — o detalhe se perdia.
            $table->text('description')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
