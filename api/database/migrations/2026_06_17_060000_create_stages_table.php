<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();   // estável (não muda ao renomear) — usado em deals.stage
            $table->string('name');            // rótulo editável
            $table->string('color')->default('#8696a0');
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        DB::table('stages')->insert([
            ['key' => 'novo', 'name' => 'Novo lead', 'color' => '#53bdeb', 'position' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'contato', 'name' => 'Contato feito', 'color' => '#7c6cf5', 'position' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'proposta', 'name' => 'Proposta enviada', 'color' => '#3aa6ff', 'position' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'negociacao', 'name' => 'Negociação', 'color' => '#ffb443', 'position' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'fechado', 'name' => 'Fechado', 'color' => '#25D366', 'position' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stages');
    }
};
