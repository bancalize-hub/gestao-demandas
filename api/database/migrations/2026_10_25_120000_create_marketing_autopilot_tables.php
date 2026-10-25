<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Piloto automático do marketing: gera criativo com IA, publica e otimiza sozinho.
 *
 * Três tabelas porque são três perguntas diferentes: "o que o robô pode fazer"
 * (configuração, uma por empresa), "o que o robô pôs no ar" (anúncios dele, com idade e
 * motivo de pausa — é isso que permite matar por vida útil) e "o que ele decidiu e por quê"
 * (diário — sem ele, dinheiro sai da conta e ninguém sabe explicar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_autopilots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->boolean('enabled')->default(false);
            // Teto da CONTA INTEIRA (soma dos orçamentos diários com anúncio ativo), não só do
            // que o robô criou: o saldo pré-pago é um só, e um teto que ignora as campanhas
            // antigas deixaria o total passar do combinado sem ninguém ver.
            $table->decimal('daily_cap', 10, 2)->default(100);
            // ~R$ 25/dia é o teto medido por criativo — acima disso o real extra só compra lixo.
            $table->decimal('budget_per_ad', 10, 2)->default(25);
            $table->unsignedSmallInteger('max_new_per_day')->default(2);
            $table->unsignedSmallInteger('lifetime_days')->default(7);
            $table->unsignedSmallInteger('min_triados')->default(8);
            $table->unsignedSmallInteger('kill_rate')->default(25);
            $table->decimal('max_spend_no_qualified', 10, 2)->default(60);
            $table->boolean('use_stock')->default(true);
            $table->string('image_provider', 20)->nullable();
            $table->text('image_api_key')->nullable();
            $table->string('image_model', 80)->nullable();
            $table->json('targeting')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamp('run_requested_at')->nullable();
            // simular | real | amostra — o que o botão do painel pediu.
            $table->string('run_requested_mode', 12)->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->text('last_result')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_auto_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->foreignId('marketing_creative_id')->nullable()->index();
            $table->string('campaign_id', 40)->nullable();
            $table->string('adset_id', 40)->nullable();
            $table->string('ad_id', 40)->nullable()->index();
            $table->decimal('daily_budget', 10, 2)->default(0);
            $table->string('status', 20)->default('ativo')->index(); // ativo | pausado
            $table->json('copy')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_autopilot_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->string('kind', 32)->index();
            $table->text('message');
            $table->json('data')->nullable();
            $table->boolean('simulated')->default(false);
            $table->timestamps();
        });

        Schema::table('marketing_creatives', function (Blueprint $table) {
            // upload | ia — o que o robô gerou precisa ser distinguível do que gente fez.
            $table->string('source', 12)->default('upload')->after('notes');
            // O briefing que gerou a peça (registro, gatilho, texto, prompt): é o que permite
            // a próxima geração aprender com ela.
            $table->json('brief')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_creatives', function (Blueprint $table) {
            $table->dropColumn(['source', 'brief']);
        });
        Schema::dropIfExists('marketing_autopilot_events');
        Schema::dropIfExists('marketing_auto_ads');
        Schema::dropIfExists('marketing_autopilots');
    }
};
