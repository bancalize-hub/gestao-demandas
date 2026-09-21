<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // Quando a gravação automática da sala do Meet foi ligada com sucesso.
            // NULL = ainda não conseguiu (conta sem escopo/Workspace, convite de terceiro);
            // o calendar-sync tenta de novo enquanto a reunião for futura.
            $table->timestamp('auto_record_enabled_at')->nullable()->after('recording_link');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('auto_record_enabled_at');
        });
    }
};
