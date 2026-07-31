<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API oficial do WhatsApp (Cloud API da Meta) ao lado da Evolution.
 *
 * Cada número (wa_accounts) passa a declarar por qual canal fala: 'evolution'
 * (Baileys, não-oficial — o que já existia) ou 'cloud' (Meta). As credenciais
 * ficam POR CONTA e não no .env porque o CRM é multi-empresa: cada empresa tem
 * seu próprio app/WABA na Meta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_accounts', function (Blueprint $table) {
            $table->string('provider', 16)->default('evolution')->after('name');

            // Conta cloud não tem instância na Evolution — o unique aceita vários NULL.
            $table->string('instance')->nullable()->change();

            $table->string('phone_number_id')->nullable()->after('instance');  // remetente na Graph API
            $table->string('waba_id')->nullable()->after('phone_number_id');   // conta WhatsApp Business (templates)
            $table->text('access_token')->nullable()->after('waba_id');        // system user token (criptografado)
            $table->text('app_secret')->nullable()->after('access_token');     // assina o webhook (criptografado)
            $table->string('verify_token')->nullable()->after('app_secret');   // handshake do webhook
            $table->boolean('coexistence')->default(false)->after('verify_token'); // número também no app Business
            $table->string('graph_version', 8)->nullable()->after('coexistence');

            $table->index('phone_number_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            // Na Cloud API a mídia é baixada por um id próprio (não pelo id da mensagem).
            // Sem ->after(): coluna no fim da tabela deixa o MySQL 8 usar ALGORITHM=INSTANT
            // (messages tem dezenas de milhares de linhas e isso roda na base de produção).
            $table->string('wa_media_id', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wa_accounts', function (Blueprint $table) {
            $table->dropIndex(['phone_number_id']);
            $table->dropColumn([
                'provider', 'phone_number_id', 'waba_id', 'access_token',
                'app_secret', 'verify_token', 'coexistence', 'graph_version',
            ]);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('wa_media_id');
        });
    }
};
