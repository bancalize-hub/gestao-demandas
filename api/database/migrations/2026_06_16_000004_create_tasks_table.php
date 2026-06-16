<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('client')->nullable();
            $table->string('priority', 16)->default('media'); // baixa|media|alta
            $table->string('due', 32)->nullable();
            $table->string('type', 32)->nullable();
            $table->string('column', 16)->default('todo'); // todo|doing|review|done
            $table->integer('position')->default(0); // pode ser negativo (novas tarefas entram no topo)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
