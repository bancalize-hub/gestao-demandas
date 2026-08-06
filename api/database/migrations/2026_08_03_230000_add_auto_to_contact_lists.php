<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_lists', function (Blueprint $table) {
            // Lista que se alimenta sozinha: guarda o CRITÉRIO com que foi criada e volta a
            // aplicá-lo sozinha (lists:sync), em vez de ser uma foto do dia em que nasceu.
            $table->boolean('auto')->default(false)->after('kind');
            $table->json('criteria')->nullable()->after('auto');
            $table->timestamp('synced_at')->nullable()->after('criteria');
        });

        // As listas de anúncio SEMPRE se alimentaram sozinhas (os webhooks registram na
        // hora); marcar aqui só deixa isso explícito e as inclui no tick de manutenção,
        // que é o que recupera o lead cujo telefone só apareceu depois (@lid).
        Schema::hasTable('contact_lists') && DB::table('contact_lists')
            ->where('kind', 'anuncio')->update(['auto' => true]);
    }

    public function down(): void
    {
        Schema::table('contact_lists', function (Blueprint $table) {
            $table->dropColumn(['auto', 'criteria', 'synced_at']);
        });
    }
};
