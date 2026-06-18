<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Atendimento automático: quando ligado, a IA responde o lead sozinha.
            $table->boolean('auto_reply')->default(false)->after('archived');
            // Quando há uma resposta automática pendente (debounce de rajadas de mensagens).
            $table->timestamp('auto_reply_due_at')->nullable()->after('auto_reply');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['auto_reply', 'auto_reply_due_at']);
        });
    }
};
