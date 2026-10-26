<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordens que o dono dá ao gestor pelo WhatsApp ("não suba nada com cara de banco", "pode ir
 * até R$ 150"). Ficam na configuração, e não só na conversa, porque têm que valer em TODO
 * turno seguinte — e o dono precisa conseguir ver e revogar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_autopilots', function (Blueprint $table) {
            $table->json('owner_orders')->nullable()->after('instructions');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_autopilots', fn (Blueprint $t) => $t->dropColumn('owner_orders'));
    }
};
