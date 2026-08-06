<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // Reunião cancelada/remarcada: deixa de contar como "agendamento ativo" do contato
            // (regra de ouro: no máximo UM ativo por contato) e some dos lembretes/apuração.
            // Guardamos em vez de apagar para preservar o histórico do lead.
            $table->dateTime('cancelled_at')->nullable()->after('google_event_id');
            $table->string('cancel_reason')->nullable()->after('cancelled_at'); // ex.: "remarcada", "pedido do cliente"
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
