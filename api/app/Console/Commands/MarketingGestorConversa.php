<?php

namespace App\Console\Commands;

use App\Models\MarketingAutopilot;
use App\Services\Marketing\GestorAgente;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Responde o dono no WhatsApp: aberto pelo webhook assim que a mensagem dele chega.
 *
 * Um turno por vez por empresa (mesma trava do turno de revisão). Se outro turno já está
 * rodando, sai — a mensagem fica não lida e esse turno, ou a próxima chamada, a pega.
 * Fica em laço enquanto chegar mensagem nova durante a resposta.
 */
class MarketingGestorConversa extends Command
{
    protected $signature = 'marketing:gestor-conversa {--empresa= : empresa}';

    protected $description = 'Gestor de tráfego responde as mensagens do dono no WhatsApp';

    public function handle(Tenancy $tenancy): int
    {
        $empresa = (int) $this->option('empresa');
        $lock = Cache::lock("gestor-turno:{$empresa}", 2400);
        if (! $lock->get()) {
            $this->line('Outro turno do gestor está rodando: a mensagem será lida por ele ou pela próxima chamada.');

            return self::SUCCESS;
        }

        try {
            $tenancy->run($empresa, function () {
                for ($i = 0; $i < 5; $i++) {
                    $s = (new GestorAgente(MarketingAutopilot::atual()))->conversar();
                    if (! $s) {
                        break;
                    }
                    $this->line("conversa #{$s->id} {$s->status}");
                }
            });
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
