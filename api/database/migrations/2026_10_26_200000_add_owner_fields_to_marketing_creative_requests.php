<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido ao designer feito pelo dono no WhatsApp: a peça volta para ele ver antes de ir ao ar,
 * pode trazer imagens de exemplo que ele mandou e pode ser o ajuste de um criativo existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_creative_requests', function (Blueprint $t) {
            $t->boolean('para_dono')->default(false)->after('status');
            $t->json('imagens')->nullable()->after('evitar');
            $t->unsignedInteger('ajustar_criativo')->nullable()->after('imagens');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_creative_requests', function (Blueprint $t) {
            $t->dropColumn(['para_dono', 'imagens', 'ajustar_criativo']);
        });
    }
};
