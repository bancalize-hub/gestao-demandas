<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Conversation;
use App\Support\Avatars;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Busca as fotos de perfil dos contatos na Evolution e guarda o binário no disco.
 *
 * Roda por empresa, e só nas que têm alguma instância Evolution CONECTADA — é ela que
 * consegue a foto de qualquer telefone. A empresa que só tem o número oficial da Meta
 * fica sem foto porque a Meta não entrega essa informação; não há o que buscar.
 */
class AvatarsSync extends Command
{
    protected $signature = 'wa:avatars
        {--empresa= : Só esta empresa (id)}
        {--limite=200 : Máximo de conversas por empresa nesta rodada}
        {--todas : Rebusca também as que já têm foto no disco}';

    protected $description = 'Baixa as fotos de perfil dos contatos (via Evolution) para o disco';

    public function handle(Tenancy $tenancy): int
    {
        $empresas = Company::query()
            ->when($this->option('empresa'), fn ($q, $id) => $q->whereKey($id))
            ->pluck('id');

        $total = 0;

        foreach ($empresas as $companyId) {
            $instancia = Avatars::instanciaConectada($companyId);
            if (! $instancia) {
                $this->warn("Empresa {$companyId}: nenhum número Evolution conectado — sem fonte de foto, pulando.");

                continue;
            }

            $novas = $tenancy->run($companyId, fn () => $this->daEmpresa($instancia));
            $total += $novas;
            $this->info("Empresa {$companyId} ({$instancia}): {$novas} fotos novas.");
        }

        $this->info("Total: {$total} fotos.");

        return self::SUCCESS;
    }

    private function daEmpresa(string $instancia): int
    {
        $limite = max(1, (int) $this->option('limite'));

        $conversas = Conversation::query()
            ->whereNotNull('phone')
            ->where('phone', '<>', '')
            // As mais recentes primeiro: é a foto de quem você está conversando AGORA que
            // faz falta. Com o limite por rodada, o resto entra nas próximas passadas.
            ->orderByRaw('last_message_at IS NULL, last_message_at DESC')
            ->limit($limite * 3)
            ->get(['id', 'phone', 'avatar', 'company_id']);

        if (! $this->option('todas')) {
            $conversas = $conversas->reject(fn ($c) => Avatars::temArquivo($c));
        }

        $conversas = $conversas->take($limite);
        if ($conversas->isEmpty()) {
            return 0;
        }

        $novas = 0;
        // Lotes de 20: a Evolution atende em paralelo, mas uma rajada de 200 sockets
        // Baileys de uma vez derruba a instância.
        foreach ($conversas->chunk(20) as $lote) {
            $novas += Avatars::buscarNaEvolution($lote, $instancia);
        }

        return $novas;
    }
}
