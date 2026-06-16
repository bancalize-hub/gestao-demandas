<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16)->default('text'); // divider|text|voice|file
            $table->boolean('is_out')->default(false);
            $table->text('text')->nullable();
            $table->string('time', 16)->nullable();
            $table->string('dur', 16)->nullable();
            $table->string('file_name')->nullable();
            $table->string('meta')->nullable();
            $table->string('label')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
