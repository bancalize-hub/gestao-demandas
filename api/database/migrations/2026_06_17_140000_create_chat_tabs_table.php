<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_tabs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->json('stages')->nullable(); // chaves das etapas/etiquetas que a tab filtra
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_tabs');
    }
};
