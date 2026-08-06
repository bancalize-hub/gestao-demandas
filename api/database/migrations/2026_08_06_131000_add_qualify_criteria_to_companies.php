<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Critério de qualificação por empresa.
 *
 * O que é um lead bom muda de negócio para negócio — quem vende plataforma financeira
 * white-label descarta exatamente o perfil que uma financeira disputaria. Deixar isso
 * no código faria a triagem da IA acertar para uma empresa e mentir para todas as outras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('qualify_enabled')->default(false)->after('nudge_enabled');
            $table->text('qualify_criteria')->nullable()->after('qualify_enabled');
        });

        // Critério da empresa 1 (Bancalize), ditado pelo dono da conta: a plataforma é
        // vendida para quem vai OPERAR serviço financeiro, não para quem quer consumir um.
        $criterio = <<<'TXT'
        Vendemos uma plataforma white-label de banking e pagamentos (setup a partir de
        R$ 5 mil + taxa sobre o volume transacionado). É B2B e exige CNPJ.

        QUALIFICADO: quer montar ou já opera uma estrutura financeira própria — banco
        digital, fintech, gateway, sub-adquirente, maquininha com a própria marca — para
        atender os clientes ou lojistas DELE. Também vale quem já tem operação rodando,
        volume relevante ou tecnologia e só precisa do parceiro BaaS.

        DESQUALIFICADO: quer empréstimo, crédito ou dinheiro emprestado para si; quer
        abrir conta digital para uso próprio; quer só uma maquininha ou meio de pagamento
        para o próprio caixa; ou não sabe o que é a empresa nem o que veio buscar.
        TXT;

        DB::table('companies')->where('id', 1)->update([
            'qualify_enabled' => true,
            'qualify_criteria' => $criterio,
        ]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['qualify_enabled', 'qualify_criteria']);
        });
    }
};
