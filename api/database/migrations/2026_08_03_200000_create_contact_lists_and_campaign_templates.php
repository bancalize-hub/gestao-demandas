<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listas de contatos + campanha pela API oficial.
 *
 * Listas: a agenda deixa de ser "o que veio do Google" e passa a ser vários grupos
 * (Google, planilha importada, leads de anúncio…). Um contato pode estar em mais de
 * uma lista — daí o pivô em vez de uma coluna em `contacts`.
 *
 * Campanha: no canal oficial não existe disparo com texto livre (o contato nunca
 * escreveu, a janela de 24h está fechada). O que a Meta permite é TEMPLATE aprovado,
 * então a campanha guarda qual template usar e o que vai em cada variável.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('name', 120);
            // google | planilha | crm | manual — só para a tela explicar de onde veio.
            $table->string('kind', 20)->default('manual');
            $table->timestamps();
        });

        Schema::create('contact_list_contact', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_list_id')->index();
            $table->foreignId('contact_id')->index();
            $table->timestamps();
            $table->unique(['contact_list_id', 'contact_id']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('template_name')->nullable()->after('objective');
            $table->string('template_language', 16)->nullable()->after('template_name');
            $table->text('template_body')->nullable()->after('template_language');   // p/ espelhar o texto no chat
            $table->json('template_params')->nullable()->after('template_body');     // um item por {{n}}, com {nome} etc.
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['template_name', 'template_language', 'template_body', 'template_params']);
        });
        Schema::dropIfExists('contact_list_contact');
        Schema::dropIfExists('contact_lists');
    }
};
