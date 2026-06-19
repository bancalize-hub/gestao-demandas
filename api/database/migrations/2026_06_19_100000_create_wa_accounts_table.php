<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contas de WhatsApp (uma por número/instância da Evolution).
 *  - role=primary  : número que atende leads de anúncio (auto-reply IA ligado), como hoje.
 *  - role=outreach : números de prospecção/disparo em massa (mensagem da IA; respostas
 *                    caem no CRM sem auto-reply, atendidas por humano).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // rótulo amigável (ex.: "Principal", "Prospecção 1")
            $table->string('instance')->unique();         // nome da instância na Evolution API
            $table->string('phone')->nullable();          // número conectado (só dígitos), preenchido ao conectar
            $table->string('role')->default('outreach');  // primary | outreach
            $table->string('state')->default('close');    // cache do estado: open | connecting | close
            $table->boolean('is_active')->default(true);
            // Anti-ban (conservador): teto diário e aquecimento gradual de números novos.
            $table->unsignedInteger('daily_cap')->default(40);
            $table->unsignedInteger('warmup_day')->default(0); // dias desde a 1ª campanha (0 = ainda não aqueceu)
            $table->unsignedInteger('sent_today')->default(0);
            $table->date('sent_date')->nullable();        // dia a que sent_today se refere (reset diário)
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        // Semeia a instância atual como número PRINCIPAL, preservando o comportamento existente.
        $instance = (string) config('services.evolution.instance');
        if ($instance !== '') {
            DB::table('wa_accounts')->insert([
                'name' => 'Principal',
                'instance' => $instance,
                'role' => 'primary',
                'is_active' => true,
                'daily_cap' => 0, // principal não dispara campanhas; cap não se aplica
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_accounts');
    }
};
