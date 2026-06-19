<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Linha do tempo real do lead (substitui o JSON estático conversations.interactions).
        // Recebe tanto notas manuais quanto eventos automáticos (mudança de etapa, reunião, follow-up).
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = sistema
            $table->string('type', 24)->default('nota'); // nota|etapa|reuniao|followup|whatsapp
            $table->string('title');
            $table->text('body')->nullable();
            $table->dateTime('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
