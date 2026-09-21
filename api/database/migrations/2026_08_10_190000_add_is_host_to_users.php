<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Anfitrião de reuniões NOVAS. Na migração p/ Google Workspace as contas @gmail
            // antigas ficam conectadas (leitura da agenda velha + apuração das reuniões já
            // marcadas) mas não devem mais RECEBER reunião — is_host=false as tira do pool
            // do agendador sem desconectar nada.
            $table->boolean('is_host')->default(true)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_host');
        });
    }
};
