<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy: cada empresa atende seus próprios clientes, isolada das demais.
 *
 *  - Cria a tabela `companies` (o tenant).
 *  - Adiciona `company_id` a todas as tabelas de domínio + `users`.
 *  - Adiciona `is_super_admin` (dono da plataforma) em `users`.
 *  - Backfill: tudo que já existe passa a pertencer à "Empresa 1" (a operação atual),
 *    preservando o comportamento single-tenant de hoje sem perda de dados.
 *
 * Sem FK constraint explícita (portável MySQL/SQLite); o isolamento é garantido
 * pelo CompanyScope/BelongsToCompany na camada de aplicação, e o índice acelera o filtro.
 */
return new class extends Migration
{
    /** Tabelas de domínio que passam a ser isoladas por empresa. */
    private array $domainTables = [
        'conversations', 'messages', 'deals', 'tasks', 'stages', 'labels', 'contacts',
        'quick_replies', 'chat_tabs', 'memory_chunks', 'style_profiles', 'style_samples',
        'style_rules', 'wa_accounts', 'campaigns', 'campaign_contacts', 'meetings',
        'lead_activities', 'agent_sessions', 'agent_messages', 'agent_jobs',
        'stage_automations', 'stage_automation_steps', 'stage_automation_runs',
    ];

    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();          // usado na URL do portal público do cliente
            $table->boolean('is_active')->default(true); // empresa suspensa não acessa
            $table->timestamps();
        });

        // Usuários pertencem a uma empresa; is_super_admin = dono da plataforma (opera a VPS).
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->boolean('is_super_admin')->default(false);
        });

        foreach ($this->domainTables as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'company_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->unsignedBigInteger('company_id')->nullable()->index();
                });
            }
        }

        // --- Backfill: a operação atual vira a "Empresa 1" ---
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa 1',
            'slug' => 'empresa-1',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->whereNull('company_id')->update(['company_id' => $companyId]);
        // Os admins atuais são os donos da plataforma (mantêm acesso ao /agente e ao painel).
        DB::table('users')->where('is_admin', true)->update(['is_super_admin' => true]);

        foreach ($this->domainTables as $t) {
            if (Schema::hasTable($t)) {
                DB::table($t)->whereNull('company_id')->update(['company_id' => $companyId]);
            }
        }

        // --- Unicidades que eram globais passam a ser por empresa ---
        // (duas empresas podem ter a etapa "novo", ou o mesmo telefone como lead).
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->unique(['company_id', 'slug']);
        });
        Schema::table('stages', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->unique(['company_id', 'key']);
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['resource_name']);
            $table->unique(['company_id', 'resource_name']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'slug']);
            $table->unique('slug');
        });
        Schema::table('stages', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'key']);
            $table->unique('key');
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'resource_name']);
            $table->unique('resource_name');
        });

        foreach ($this->domainTables as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'company_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropColumn('company_id');
                });
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['company_id', 'is_super_admin']);
        });

        Schema::dropIfExists('companies');
    }
};
