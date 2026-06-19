<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Transcrição do áudio (voice) feita pelo Whisper/Groq. null = ainda não transcrito.
            $table->text('transcript')->nullable()->after('text');
            // Tentativas de transcrição (trava de retry — desiste depois de algumas falhas).
            $table->unsignedTinyInteger('transcribe_attempts')->default(0)->after('transcript');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['transcript', 'transcribe_attempts']);
        });
    }
};
