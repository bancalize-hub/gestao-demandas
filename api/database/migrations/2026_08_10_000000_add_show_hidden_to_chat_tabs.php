<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tab que mostra as conversas EXCLUÍDAS — a lixeira do chat.
 *
 * "Excluir" aqui nunca apagou nada: a conversa só ganha `hidden_at` e sai da tela. Só que
 * até agora não havia por onde revê-la — o Desfazer dura oito segundos e depois o histórico
 * ficava alcançável apenas por consulta no banco. Como a tab já é o recorte configurável da
 * lista (etiqueta + triagem), a lixeira nasce como mais um filtro dela em vez de virar uma
 * tela nova: quem precisa ver os excluídos cria a tab e pronto.
 *
 * É EXCLUSIVO, não aditivo: a tab marcada mostra SÓ os excluídos. Misturar apagado com ativo
 * na mesma lista tira justamente a informação que importa — qual é qual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_tabs', function (Blueprint $table) {
            $table->boolean('show_hidden')->default(false)->after('qualified');
        });
    }

    public function down(): void
    {
        Schema::table('chat_tabs', function (Blueprint $table) {
            $table->dropColumn('show_hidden');
        });
    }
};
