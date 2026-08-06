<?php

namespace App\Console\Commands;

use App\Models\MarketingCredential;
use App\Support\MetaConversions;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Cria no Facebook as CONVERSÕES PERSONALIZADAS dos quatro momentos do funil.
 *
 * O evento em si não se "cria" na Meta — ele passa a existir quando chega. O que se
 * cria é a conversão personalizada: o objeto que aparece na lista de objetivos quando
 * você monta uma campanha de conversão. Sem ela, o evento chega ao Gerenciador de
 * Eventos mas não dá para escolher como meta de otimização.
 *
 * Idempotente: conversão que já existe com o mesmo nome é pulada, não duplicada.
 */
class CapiConversoes extends Command
{
    protected $signature = 'capi:conversoes {--empresa=1 : Empresa dona da conta de anúncios} {--listar : Só mostra o que já existe}';

    protected $description = 'Cria as conversões personalizadas do funil na conta de anúncios';

    /**
     * `custom_event_type` NÃO é livre: a Meta exige a categoria correspondente ao nome do
     * evento (o enum é INITIATED_CHECKOUT e CONTENT_VIEW — não os nomes dos eventos).
     * Como os nomes são impostos pelo vocabulário de business_messaging (ver
     * MetaConversions), a categoria vem junto — não há escolha a fazer aqui.
     */
    private const CONVERSOES = [
        [MetaConversions::LEAD, 'CRM · Lead no CRM', 'LEAD'],
        [MetaConversions::REUNIAO_MARCADA, 'CRM · Reunião marcada', 'INITIATED_CHECKOUT'],
        [MetaConversions::REUNIAO_REALIZADA, 'CRM · Reunião realizada', 'CONTENT_VIEW'],
        [MetaConversions::VENDA, 'CRM · Venda fechada', 'PURCHASE'],
    ];

    public function handle(Tenancy $tenancy): int
    {
        return $tenancy->run((int) $this->option('empresa'), fn () => $this->executar());
    }

    private function executar(): int
    {
        $cred = MarketingCredential::atual();
        $dataset = trim((string) $cred->dataset_id);

        if (blank($cred->access_token) || blank($cred->ad_account_id)) {
            $this->error('Falta token de acesso ou conta de anúncios.');

            return self::FAILURE;
        }
        if ($dataset === '') {
            $this->error('Falta o ID do dataset. Preencha em Conexão Facebook Ads.');

            return self::FAILURE;
        }

        $base = 'https://graph.facebook.com/'.trim($cred->graph_version ?: 'v23.0');
        $conta = str_starts_with((string) $cred->ad_account_id, 'act_') ? $cred->ad_account_id : 'act_'.$cred->ad_account_id;
        $token = $cred->access_token;

        // O que já existe, para não duplicar. Conversão apagada continua saindo nesta
        // listagem — só some do Gerenciador — e volta com `is_archived: true`. Se ela
        // contasse como "já existe", nunca daria para recriar uma com o mesmo nome.
        $todas = collect(
            Http::timeout(30)->get("{$base}/{$conta}/customconversions", [
                'access_token' => $token, 'fields' => 'id,name,is_archived', 'limit' => 100,
            ])->json('data') ?? []
        );
        $existentes = $todas->reject(fn ($c) => (bool) ($c['is_archived'] ?? false))->pluck('id', 'name');

        if ($this->option('listar')) {
            $this->info("Conversões na conta {$conta}:");
            foreach ($todas as $c) {
                $this->line(sprintf('  %-20s %s%s', $c['id'], $c['name'] ?? '?', ($c['is_archived'] ?? false) ? '  (arquivada/apagada)' : ''));
            }

            return self::SUCCESS;
        }

        foreach (self::CONVERSOES as [$evento, $nome, $tipo]) {
            if ($existentes->has($nome)) {
                $this->line("· {$nome} — já existe ({$existentes[$nome]})");

                continue;
            }

            // A regra casa pelo nome do evento E pela marca de origem. As DUAS condições
            // são obrigatórias: com só a do evento a Graph API responde "A conversion rule
            // is required at creation time" e não cria nada (testado contra a API real).
            $regra = json_encode([
                'and' => [
                    ['event' => ['eq' => $evento]],
                    ['or' => [['origem' => ['eq' => MetaConversions::ORIGEM]]]],
                ],
            ]);

            $res = Http::timeout(30)->asForm()->post("{$base}/{$conta}/customconversions", [
                'access_token' => $token,
                'name' => $nome,
                'event_source_id' => $dataset,
                'custom_event_type' => $tipo,
                'rule' => $regra,
                'description' => "Disparado pelo CRM quando o lead atinge esta etapa (evento {$evento}).",
            ]);

            $json = $res->json();
            if (! empty($json['id'])) {
                $this->info("✓ {$nome} criada ({$json['id']}) — evento {$evento}");
            } else {
                $msg = $json['error']['error_user_msg'] ?? $json['error']['message'] ?? 'erro desconhecido';
                $this->error("✗ {$nome}: {$msg}");
            }
        }

        return self::SUCCESS;
    }
}
