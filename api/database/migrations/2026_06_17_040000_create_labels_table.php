<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color')->default('#7c6cf5');
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        // Etiquetas espelham os estágios do funil de vendas.
        DB::table('labels')->insert([
            ['name' => 'Novo lead', 'color' => '#53bdeb', 'position' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Contato feito', 'color' => '#7c6cf5', 'position' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Proposta enviada', 'color' => '#3aa6ff', 'position' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Negociação', 'color' => '#ffb443', 'position' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Fechado', 'color' => '#25D366', 'position' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('labels');
    }
};
