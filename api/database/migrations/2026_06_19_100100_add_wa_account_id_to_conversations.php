<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Carimba cada conversa com o número (conta) a que pertence. Backfill -> principal. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('wa_account_id')->nullable()->after('phone')->index();
        });

        // Tudo que já existe pertence ao número principal.
        $primaryId = DB::table('wa_accounts')->where('role', 'primary')->value('id');
        if ($primaryId) {
            DB::table('conversations')->whereNull('wa_account_id')->update(['wa_account_id' => $primaryId]);
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('wa_account_id');
        });
    }
};
