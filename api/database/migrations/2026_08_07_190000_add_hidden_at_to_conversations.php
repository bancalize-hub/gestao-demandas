<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Excluir" conversa — que na verdade é esconder.
 *
 * POR QUE NÃO É SoftDeletes: o `deleted_at` do Laravel vem com global scope, e os dois
 * webhooks acham a conversa por `Conversation::firstOrNew(['slug' => …])`. Com o scope
 * ligado, a linha escondida ficaria invisível para o firstOrNew e a próxima mensagem do
 * mesmo contato criaria uma conversa DUPLICADA com o mesmo slug — histórico partido ao
 * meio e o slug único estourando. Coluna crua, sem scope, mantém o webhook enxergando
 * a linha; quem filtra é a listagem.
 *
 * POR QUE NÃO É `archived`: arquivar é reversível pela tela e a aba "Arquivadas" existe
 * justamente para revisitar. Excluir é outra intenção — sumir da tela sem deixar aba.
 *
 * A conversa VOLTA se o contato mandar mensagem nova (mesma regra do WhatsApp: apagar a
 * conversa não bloqueia ninguém). É de propósito: esconder um lead por engano e nunca
 * mais ver o retorno dele é caro; ver de novo é só um clique de incômodo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('archived')->index();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['hidden_at']);
            $table->dropColumn('hidden_at');
        });
    }
};
