<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('initials', 4);
            $table->string('color', 16);
            $table->boolean('online')->default(false);
            $table->string('status_text')->nullable();
            $table->string('role')->nullable();
            $table->string('deal_value')->nullable();
            $table->string('deal_unit')->nullable();
            $table->string('stage')->nullable();
            $table->string('stage_color', 16)->nullable();
            $table->unsignedTinyInteger('prob')->default(0);
            $table->boolean('hot')->default(false);
            $table->string('preview')->nullable();
            $table->string('time', 16)->nullable();
            $table->unsignedInteger('unread')->default(0);
            $table->json('tags')->nullable();
            // Ficha do lead
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('company')->nullable();
            $table->string('origin')->nullable();
            $table->string('responsible')->nullable();
            $table->json('interactions')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
