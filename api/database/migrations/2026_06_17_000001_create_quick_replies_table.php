<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_replies', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->text('text');
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        // Mantém as 3 respostas que eram fixas no front.
        DB::table('quick_replies')->insert([
            ['label' => 'Agendar demonstração', 'text' => 'Podemos agendar uma demonstração de 20 minutos? Te mostro o sistema na prática.', 'position' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['label' => 'Enviar tabela de preços', 'text' => 'Segue a nossa tabela de preços atualizada. Qualquer dúvida é só chamar! 💰', 'position' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['label' => 'Condições de pagamento', 'text' => 'Trabalhamos com pagamento mensal ou anual (com desconto). Qual formato faz mais sentido pra você?', 'position' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_replies');
    }
};
