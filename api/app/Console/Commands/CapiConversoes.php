<?php

namespace App\Console\Commands;

use App\Models\MarketingCredential;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Lista as conversões personalizadas da conta. NÃO CRIA MAIS NENHUMA.
 *
 * ISTO AQUI ERA UM CAMINHO SEM SAÍDA, e a medição está guardada para ninguém refazer:
 * conversão personalizada é avaliada sobre evento de PIXEL/WEB e simplesmente não
 * enxerga evento com `action_source: business_messaging`, que é o único jeito de
 * reportar o funil de um lead que entra por Click-to-WhatsApp.
 *
 * A prova, colhida na conta em 08/08/2026:
 *  - "CRM · Lead no CRM" — regra `LeadSubmitted` + `origem=crm`, as duas condições
 *    presentes em todo payload nosso — recebeu ~87 eventos casáveis depois de criada e
 *    tem `last_fired_time` VAZIO. O mesmo vale para as outras quatro.
 *  - "Demonstração" — regra `PageView` + URL, evento de site — disparou normalmente na
 *    MESMA conta. Ou seja: o mecanismo funciona, os nossos eventos é que não entram nele.
 *  - Nas ações da conta não existe nenhum `offsite_conversion.custom.*`; os nossos
 *    eventos aparecem como `onsite_conversion.lead`, `.initiate_checkout`, `.view_content`.
 *
 * Consequência de projeto: o que separa um momento do funil do outro é o NOME DO EVENTO,
 * e são só quatro (ver MetaConversions). Por isso `LeadSubmitted` passou a ser gasto com
 * o lead QUALIFICADO em vez de com "chegou um lead".
 *
 * As cinco conversões "CRM · …" foram arquivadas: mantê-las na lista de objetivos só
 * levava alguém a montar campanha atrás de um alvo que nunca dispara — foi exatamente o
 * que aconteceu com a campanha "Conversão (Lead) - Criativo 7", que gastou sem entregar.
 */
class CapiConversoes extends Command
{
    protected $signature = 'capi:conversoes {--empresa=1 : Empresa dona da conta de anúncios}';

    protected $description = 'Lista as conversões personalizadas da conta (criar não adianta — ver a classe)';

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

        $todas = collect(
            Http::timeout(30)->get("{$base}/{$conta}/customconversions", [
                'access_token' => $token, 'fields' => 'id,name,is_archived,last_fired_time', 'limit' => 100,
            ])->json('data') ?? []
        );

        $this->info("Conversões personalizadas na conta {$conta}:");
        foreach ($todas as $c) {
            $this->line(sprintf(
                '  %-20s %-30s %s  %s',
                $c['id'],
                mb_substr((string) ($c['name'] ?? '?'), 0, 30),
                ($c['is_archived'] ?? false) ? 'arquivada' : 'ativa    ',
                isset($c['last_fired_time']) ? 'disparou em '.$c['last_fired_time'] : 'NUNCA disparou',
            ));
        }

        $this->newLine();
        $this->warn('Este comando não cria mais conversões: as do CRM nunca disparam.');
        $this->line('Evento com action_source=business_messaging não é lido por conversão');
        $this->line('personalizada. Quem separa os momentos do funil é o NOME do evento —');
        $this->line('ver App\\Support\\MetaConversions.');

        return self::SUCCESS;
    }
}
