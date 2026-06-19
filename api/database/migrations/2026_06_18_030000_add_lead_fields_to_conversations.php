<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Qualificação do lead (CRM). 'role' (cargo) e os demais campos da ficha
            // já existem; aqui entram os que faltavam para a ficha editável.
            $table->string('segmento')->nullable()->after('origin');     // ramo/segmento do cliente
            $table->text('notes')->nullable()->after('segmento');        // observações livres do lead
            $table->json('custom_fields')->nullable()->after('notes');   // campos extras sob demanda
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['segmento', 'notes', 'custom_fields']);
        });
    }
};
