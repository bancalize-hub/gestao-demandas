<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('avatar')->nullable();
            // Vínculo com o Google People API
            $table->string('resource_name')->nullable()->unique(); // people/c123...
            $table->string('etag')->nullable();
            $table->foreignId('google_user_id')->nullable();
            $table->timestamps();
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
