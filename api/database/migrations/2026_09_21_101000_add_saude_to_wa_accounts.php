<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colunas para o monitor de saúde do número (wa:health-tick).
 *
 * Até aqui o `state` da conta só era atualizado quando ALGUÉM ABRIA a tela de admin —
 * um número podia cair na sexta e ninguém saber até segunda. E foi literalmente o que
 * aconteceu: o número oficial ficou mudo de 19/09 a 21/09 e quem descobriu foi uma
 * auditoria, não o sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_accounts', function (Blueprint $t) {
            // Desde quando está no estado atual: "caiu agora" é diferente de "caiu ontem".
            $t->timestamp('state_changed_at')->nullable()->after('state');
            // Última verificação automática (não a última vez que alguém abriu a tela).
            $t->timestamp('health_checked_at')->nullable()->after('state_changed_at');
            // Quando o último alarme foi aberto, para não repetir alarme a cada 5 minutos.
            $t->timestamp('alerted_at')->nullable()->after('health_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('wa_accounts', function (Blueprint $t) {
            $t->dropColumn(['state_changed_at', 'health_checked_at', 'alerted_at']);
        });
    }
};
