<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Veredito do criativo. O desempenho vem da Meta, mas quem bate o martelo é
        // pessoa: um criativo pode ter CPL alto porque o público estava errado, e um
        // barato pode trazer lead que não fecha. O selo é o que sobrevive à troca de
        // campanha — e é ele que impede o criativo ruim de voltar ao ar meses depois.
        Schema::table('marketing_creatives', function (Blueprint $table) {
            $table->string('status', 20)->default('testando')->after('notes')->index();
            $table->text('status_note')->nullable()->after('status'); // por que reprovou/validou
            $table->timestamp('status_at')->nullable()->after('status_note');
            $table->foreignId('status_by')->nullable()->after('status_at');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_creatives', function (Blueprint $table) {
            $table->dropColumn(['status', 'status_note', 'status_at', 'status_by']);
        });
    }
};
