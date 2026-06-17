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

        DB::table('labels')->insert([
            ['name' => 'Cliente', 'color' => '#25D366', 'position' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Lead', 'color' => '#53bdeb', 'position' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Urgente', 'color' => '#ff6b6b', 'position' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Suporte', 'color' => '#ffb443', 'position' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('labels');
    }
};
