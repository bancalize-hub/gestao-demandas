<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dono do negócio e carimbo de proposta enviada.
 *
 * Medido em 18/08/2026: dos 14 negócios em "disse que vai fechar", 12 não tinham
 * responsável nenhum e 11 de 16 nunca receberam uma mensagem sobre preço ou contrato.
 * A coluna `responsible` que já existia NÃO serve: é texto livre e está poluída com o
 * nome do próprio lead ("Gilson", "kleber"), então nunca foi ownership de verdade.
 *
 * `proposal_sent_at` existe para tornar mensurável a única meta que importa depois da
 * reunião — proposta na mão do lead em 24h, que foi o caminho do único negócio fechado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('responsible')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('proposal_sent_at')->nullable()->after('owner_user_id');
            $table->dateTime('last_touch_at')->nullable()->after('proposal_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropColumn(['proposal_sent_at', 'last_touch_at']);
        });
    }
};
