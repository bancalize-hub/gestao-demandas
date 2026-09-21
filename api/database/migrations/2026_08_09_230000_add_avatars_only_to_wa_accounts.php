<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Número que fica conectado SÓ para servir foto de perfil.
 *
 * A Cloud API da Meta não entrega foto de contato — nem existe endpoint. A única fonte é
 * uma instância Evolution (Baileys) conectada, que consulta a foto de qualquer telefone,
 * inclusive de contato que só falou com o número oficial. Mas manter essa instância
 * conectada trazia junto o que não se quer mais: ela voltava a receber mensagem e a ser
 * o canal de envio das conversas antigas que ainda apontam para ela.
 *
 * Esta flag separa as duas coisas: a instância segue logada e alimentando `wa:avatars`,
 * e some do caminho de mensagem — não recebe e não envia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_accounts', function (Blueprint $table) {
            $table->boolean('avatars_only')->default(false)->after('coexistence');
        });
    }

    public function down(): void
    {
        Schema::table('wa_accounts', function (Blueprint $table) {
            $table->dropColumn('avatars_only');
        });
    }
};
