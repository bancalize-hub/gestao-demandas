<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sub')->nullable();
            $table->string('value')->nullable();
            $table->string('tag')->nullable();
            $table->string('stage', 32); // novo|contato|proposta|negociacao|fechado
            $table->boolean('hot')->default(false);
            $table->boolean('won')->default(false);
            $table->boolean('tag_strong')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
