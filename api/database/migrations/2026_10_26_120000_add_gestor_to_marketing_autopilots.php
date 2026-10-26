<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O piloto vira gestor de tráfego: revisa a conta inteira de hora em hora, mexe em verba,
 * religa, testa público, escala até um teto máximo e avisa no WhatsApp.
 *
 * Dois tetos porque são duas decisões de pessoas diferentes: `daily_cap` é o normal (o
 * gestor trabalha nele sem pedir licença) e `max_cap` é o limite duro que só sobe pela mão
 * do dono. Entre os dois, quem decide é o gestor — mas só sobe acima do normal com custo
 * por lead QUALIFICADO dentro da meta (`target_cpq`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_autopilots', function (Blueprint $table) {
            $table->decimal('max_cap', 10, 2)->default(200)->after('daily_cap');
            // Teto em vigor, escolhido pelo gestor entre daily_cap e max_cap (null = daily_cap).
            $table->decimal('current_cap', 10, 2)->nullable()->after('max_cap');
            // R$ por lead qualificado: as peças que se pagavam ficaram em R$ 9–12; as ruins, R$ 60+.
            $table->decimal('target_cpq', 10, 2)->default(30)->after('current_cap');
            $table->boolean('manage_whole_account')->default(true)->after('target_cpq');
            $table->string('alert_phone', 20)->nullable()->after('instructions');
            $table->foreignId('alert_wa_account_id')->nullable()->after('alert_phone');
            $table->string('report_hour', 5)->default('20:00')->after('alert_wa_account_id');
            $table->timestamp('last_report_at')->nullable()->after('last_run_at');
            $table->timestamp('last_review_at')->nullable()->after('last_report_at');
            $table->text('last_analysis')->nullable()->after('last_result');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_autopilots', function (Blueprint $table) {
            $table->dropColumn(['max_cap', 'current_cap', 'target_cpq', 'manage_whole_account', 'alert_phone',
                'alert_wa_account_id', 'report_hour', 'last_report_at', 'last_review_at', 'last_analysis']);
        });
    }
};
