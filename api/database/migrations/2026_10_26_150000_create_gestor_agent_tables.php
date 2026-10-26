<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O gestor deixa de ser "foto + lista de ações" e vira agente: cada turno é uma sessão em
 * que ele investiga com ferramentas, decide, age e marca quando volta.
 *
 * - `marketing_gestor_sessions`: o turno inteiro (o que ele olhou, o que concluiu) — é o
 *   que permite auditar por que um real foi gasto, e o que a próxima sessão lê para dar
 *   continuidade em vez de recomeçar do zero.
 * - `marketing_creative_requests`: os pedidos do gestor ao designer (briefing + criativos
 *   de referência) e o criativo entregue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_gestor_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->string('agente', 16)->default('gestor'); // gestor | designer
            $table->boolean('simulated')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 16)->default('rodando'); // rodando | ok | falhou
            $table->unsignedSmallInteger('ferramentas_usadas')->default(0);
            $table->longText('passos')->nullable(); // json: o que ele chamou e o que pensou
            $table->text('resumo')->nullable();
            $table->text('erro')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_creative_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->string('status', 20)->default('pendente')->index(); // pendente | produzindo | pronto | falhou
            $table->text('brief');
            $table->json('referencias')->nullable(); // números de criativo que servem de base
            $table->json('evitar')->nullable();
            $table->unsignedSmallInteger('quantidade')->default(1);
            $table->text('motivo')->nullable();
            $table->json('creative_ids')->nullable();
            $table->text('resultado')->nullable();
            $table->timestamps();
        });

        Schema::table('marketing_autopilots', function (Blueprint $table) {
            // O próprio agente escolhe quando volta (entre 1h e 6h).
            $table->timestamp('next_review_at')->nullable()->after('last_review_at');
        });

        Schema::table('marketing_autopilot_events', function (Blueprint $table) {
            $table->foreignId('session_id')->nullable()->index()->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_autopilot_events', fn (Blueprint $t) => $t->dropColumn('session_id'));
        Schema::table('marketing_autopilots', fn (Blueprint $t) => $t->dropColumn('next_review_at'));
        Schema::dropIfExists('marketing_creative_requests');
        Schema::dropIfExists('marketing_gestor_sessions');
    }
};
