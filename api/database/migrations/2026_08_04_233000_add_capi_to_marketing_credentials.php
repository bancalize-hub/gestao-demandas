<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversions API (CAPI): o dataset para onde os eventos do funil são enviados.
 *
 * `capi_test_code` é o código da aba "Eventos de teste" do Gerenciador de Eventos —
 * enquanto ele estiver preenchido, os eventos aparecem lá e NÃO entram na otimização.
 * É o jeito de conferir que o evento chega certo antes de valer para as campanhas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_credentials', function (Blueprint $table) {
            $table->string('dataset_id')->nullable()->after('page_id');
            $table->string('capi_test_code')->nullable()->after('dataset_id');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_credentials', function (Blueprint $table) {
            $table->dropColumn(['dataset_id', 'capi_test_code']);
        });
    }
};
