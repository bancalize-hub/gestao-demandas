<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // IA (atendimento automático) já ligada para conversas novas de leads
            // que chegam pelo WhatsApp — configurável em /admin/automacoes.
            $table->boolean('auto_reply_new_leads')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('auto_reply_new_leads');
        });
    }
};
