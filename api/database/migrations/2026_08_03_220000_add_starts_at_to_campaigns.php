<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando a campanha começa a disparar.
 *
 * O teto diário, o intervalo aleatório e a janela de horário existem por causa do
 * canal NÃO-oficial: são anti-ban do Baileys. Na API oficial da Meta não há esse
 * risco — o que a empresa precisa ali é dizer a partir de quando pode começar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('starts_at');
        });
    }
};
