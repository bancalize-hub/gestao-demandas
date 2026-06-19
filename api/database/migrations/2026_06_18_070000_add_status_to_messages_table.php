<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Recibo da mensagem enviada (WhatsApp): pending|sent|delivered|read|error.
            // null = recebida/sem recibo. Atualizado pelo webhook messages.update (ack do Baileys).
            $table->string('status', 12)->nullable()->after('is_out');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
