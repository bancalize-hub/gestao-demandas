<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // Gravação nativa do Meet (Workspace): id do arquivo no Drive (p/ o CRM
            // servir o vídeo na ficha via streaming) + link direto (fallback/conveniência).
            $table->string('recording_file_id')->nullable()->after('summary_source');
            $table->string('recording_link', 500)->nullable()->after('recording_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['recording_file_id', 'recording_link']);
        });
    }
};
