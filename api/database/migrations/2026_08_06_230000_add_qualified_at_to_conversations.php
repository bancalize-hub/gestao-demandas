<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando a IA julgou o lead — o relógio que decide se o palpite envelheceu.
 *
 * POR QUE PRECISA DE COLUNA PRÓPRIA: a reentrada na fila comparava
 * `last_message_at > updated_at`, e essa condição NUNCA foi verdadeira. Quem grava
 * `last_message_at` grava pelo Eloquent, que carimba `updated_at` no mesmo UPDATE —
 * as duas colunas sobem juntas, e `>` só valeria se a mensagem fosse do futuro.
 *
 * O efeito era invisível e caro: o lead de anúncio é julgado na primeira mensagem
 * ("Olá! Posso ter mais informações sobre isso?"), a IA responde null com razão, e o
 * palpite congelava ali para sempre — mesmo depois de a conversa inteira acontecer e
 * a reunião ser marcada. Em 06/08/2026 eram 197 conversas presas em `auto=1` com zero
 * elegíveis a reteste, e 4 das reuniões do dia estavam com o lead ainda "sem triagem".
 *
 * `qualified_at` só é escrito pelo tick da triagem, então ninguém mais o empurra para
 * frente e `last_message_at > qualified_at` volta a significar o que promete: chegou
 * conversa nova depois do julgamento.
 *
 * Fica NULL nas linhas existentes de propósito — é o que devolve as 197 congeladas
 * para a fila, uma vez cada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('qualified_at')->nullable()->after('qualified_auto');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('qualified_at');
        });
    }
};
