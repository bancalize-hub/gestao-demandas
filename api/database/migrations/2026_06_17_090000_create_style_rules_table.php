<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('style_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule', 400);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('style_rules');
    }
};
