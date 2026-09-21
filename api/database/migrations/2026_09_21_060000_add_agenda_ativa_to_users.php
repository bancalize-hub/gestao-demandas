<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chave de liga/desliga do AGENDAMENTO por pessoa.
 *
 * Separada de `is_host` de propósito. `is_host` diz quem FAZ PARTE do time de anfitriões
 * (estrutural, quase nunca muda); `agenda_ativa` diz se essa pessoa recebe reunião NOVA
 * hoje — é o botão do dia a dia, para quando o atendente daquele e-mail não vai trabalhar.
 *
 * Fossem o mesmo campo, desligar todo mundo seria indistinguível de "ninguém foi marcado
 * como anfitrião ainda", e o agendador cairia no fallback que marca em qualquer conta
 * conectada — exatamente o oposto do que o botão promete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nasce ligado: quem já era anfitrião continua recebendo reunião sem ninguém mexer.
            $table->boolean('agenda_ativa')->default(true)->after('is_host');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('agenda_ativa');
        });
    }
};
