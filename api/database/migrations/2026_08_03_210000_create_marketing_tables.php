<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Credencial da API do Facebook Ads. Guardada no banco (não no .env) para poder
        // ser trocada pela tela — mesmo motivo do token da IA em [Admin → Memória].
        Schema::create('marketing_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->string('app_id')->nullable();
            $table->text('app_secret')->nullable();      // encrypted cast
            $table->text('access_token')->nullable();    // encrypted cast
            $table->string('ad_account_id')->nullable(); // act_XXXXXXXX
            $table->string('page_id')->nullable();
            $table->string('graph_version')->default('v23.0');
            $table->timestamp('checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // Criativos enviados pela tela. O nome é sequencial (Criativo 1, Criativo 2…)
        // porque é assim que o usuário se refere a eles conversando com o agente.
        Schema::create('marketing_creatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->string('name');              // "Criativo 3"
            $table->unsignedInteger('number');    // 3 — usado para gerar o próximo
            $table->string('original_name')->nullable();
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('fb_image_hash')->nullable(); // preenchido no 1º uso
            $table->text('notes')->nullable();           // contexto que a IA lê
            $table->timestamps();
        });

        // O agente de marketing reusa a infra de sessões/jobs do agente operacional;
        // `kind` é o que decide qual comando o worker monta (shell livre × só MCP).
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->string('kind')->default('ops')->after('title')->index();
        });
    }

    public function down(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
        Schema::dropIfExists('marketing_creatives');
        Schema::dropIfExists('marketing_credentials');
    }
};
