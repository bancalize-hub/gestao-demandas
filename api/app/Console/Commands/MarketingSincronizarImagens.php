<?php

namespace App\Console\Commands;

use App\Models\MarketingCreative;
use App\Support\FacebookAds;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Sobe para a conta de anúncios as imagens da biblioteca que ainda não têm hash.
 *
 * POR QUE ISTO EXISTE: o desempenho por criativo se apoia no `fb_image_hash` — é ele
 * que liga "Criativo 3" aos anúncios que a Meta reporta por ad_id. Só que o hash era
 * gravado num único momento: quando o anúncio era criado POR AQUI. Criativo cuja peça
 * foi anunciada direto no Gerenciador ficava sem hash e, na tela, sem número nenhum —
 * "nunca rodou", mesmo tendo gasto dinheiro.
 *
 * O conserto se apoia em como a Meta guarda imagem: o hash vem do CONTEÚDO do arquivo,
 * e subir de novo o mesmo arquivo devolve o hash que já existe na conta em vez de criar
 * outra imagem. Então subir a biblioteca inteira não polui a conta com duplicatas: os
 * arquivos que já foram anunciados por fora reencontram o próprio hash e passam a casar
 * com os anúncios que já rodaram.
 *
 * Idempotente: quem já tem hash é pulado. Pode rodar de novo a cada lote de criativos
 * novos, e um erro em uma imagem não derruba as outras.
 */
class MarketingSincronizarImagens extends Command
{
    protected $signature = 'marketing:sincronizar-imagens
        {--empresa=1 : Empresa dona da conta de anúncios}
        {--limite=0 : Máximo de imagens por execução (0 = todas)}
        {--simular : Só mostra quantas subiriam, sem enviar nada}';

    protected $description = 'Sobe as imagens da biblioteca para a conta de anúncios e grava o hash (liga criativo ↔ desempenho)';

    public function handle(Tenancy $tenancy): int
    {
        return $tenancy->run((int) $this->option('empresa'), fn () => $this->executar());
    }

    private function executar(): int
    {
        $pendentes = MarketingCreative::whereNull('fb_image_hash')->orderBy('number')->get();

        $limite = (int) $this->option('limite');
        if ($limite > 0) {
            $pendentes = $pendentes->take($limite);
        }

        if ($pendentes->isEmpty()) {
            $this->info('Nenhum criativo sem hash — a biblioteca já está sincronizada.');

            return self::SUCCESS;
        }

        $this->info("{$pendentes->count()} criativo(s) sem hash.");

        if ($this->option('simular')) {
            foreach ($pendentes as $c) {
                $this->line("  {$c->name} — {$c->original_name}");
            }

            return self::SUCCESS;
        }

        $fb = FacebookAds::make();
        $ok = 0;
        $falhas = [];

        $barra = $this->output->createProgressBar($pendentes->count());
        $barra->start();

        foreach ($pendentes as $c) {
            try {
                $fb->garantirImagem($c);
                $ok++;
            } catch (\Throwable $e) {
                // Uma imagem apagada do disco ou recusada pela Meta não pode interromper
                // o lote: o próximo criativo não tem nada a ver com o problema dela.
                $falhas[] = "{$c->name}: ".$e->getMessage();
            }
            $barra->advance();
        }

        $barra->finish();
        $this->newLine(2);
        $this->info("{$ok} imagem(ns) sincronizada(s).");

        if ($falhas) {
            $this->warn(count($falhas).' falharam:');
            foreach ($falhas as $f) {
                $this->line('  '.$f);
            }
        }

        return self::SUCCESS;
    }
}
