<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            // Etiqueta do WhatsApp Business vinculada a esta etapa do funil (id do label no Evolution).
            $table->string('wa_label_id')->nullable()->after('goal');
        });
    }

    public function down(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->dropColumn('wa_label_id');
        });
    }
};
