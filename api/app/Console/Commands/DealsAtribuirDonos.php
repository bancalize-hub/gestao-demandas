<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Backfill de dono para os negócios que já estavam no funil quando `owner_user_id` nasceu.
 *
 * Daqui para a frente o MeetingAttendanceTick atribui sozinho, mas em 19/08/2026 havia 103
 * negócios quentes sem dono — e o watchdog levaria dias para cobrar um por um, enchendo o
 * quadro. Aqui a atribuição é em bloco, pela mesma regra: quem hospedou a reunião é dono.
 */
class DealsAtribuirDonos extends Command
{
    protected $signature = 'deals:atribuir-donos
        {--todas : inclui também os negócios frios, não só as etapas quentes}
        {--mapear= : remapeia donos legados, ex.: 1:5,4:6 (conta antiga → conta atual da MESMA pessoa)}
        {--aplicar : grava (sem isto, só simula)}';

    protected $description = 'Define o dono dos negócios sem responsável, pelo anfitrião da última reunião';

    public function handle(): int
    {
        $quentes = (array) config('services.crm_watchdog.stages_quentes', []);
        $aplicar = (bool) $this->option('aplicar');
        $tenancy = app(Tenancy::class);

        $q = Conversation::withoutGlobalScopes()
            ->whereNull('owner_user_id')
            ->where('archived', false);

        if (! $this->option('todas')) {
            $q->whereIn('stage', $quentes);
        }

        // Contas legadas: quem hospedou reunião antes da migração para o Workspace aparece
        // como a conta @gmail antiga (ex.: "Administrador" = Gmail pessoal do Paulo). Deixar
        // o negócio nesse nome cria dono fantasma — ninguém cobra um usuário que ninguém usa.
        $mapa = [];
        foreach (explode(',', (string) $this->option('mapear')) as $par) {
            [$de, $para] = array_pad(explode(':', trim($par)), 2, null);
            if (is_numeric($de) && is_numeric($para)) {
                $mapa[(int) $de] = (int) $para;
            }
        }
        if ($mapa) {
            $remapeados = Conversation::withoutGlobalScopes()->whereIn('owner_user_id', array_keys($mapa))->get();
            foreach ($remapeados as $c) {
                $this->line(sprintf('  <fg=yellow>remapeia</> %-26s %d → %d',
                    mb_substr((string) $c->name, 0, 26), $c->owner_user_id, $mapa[$c->owner_user_id]));
                if ($aplicar) {
                    $c->forceFill(['owner_user_id' => $mapa[$c->owner_user_id]])->save();
                }
            }
            $this->info(count($remapeados).' negócio(s) com dono legado.');
        }

        $semReuniao = 0;
        $definidos = 0;

        foreach ($q->get() as $c) {
            $tenancy->set($c->company_id);

            // Última reunião COM presença: quem conduziu é quem conhece o negócio. Sem
            // reunião não há a quem atribuir — chutar o primeiro usuário criaria dono
            // fantasma, que é pior que dono nenhum (ninguém cobra um nome errado).
            $host = $c->meetings()->where('attended', true)
                ->reorder()->orderByDesc('starts_at')->value('user_id')
                ?: $c->meetings()->reorder()->orderByDesc('starts_at')->value('user_id');
            $host = $mapa[$host] ?? $host;

            if (! $host) {
                $semReuniao++;

                continue;
            }

            $this->line(sprintf('  %-28s → usuário %d', mb_substr((string) $c->name, 0, 28), $host));
            $definidos++;

            if ($aplicar) {
                $c->forceFill(['owner_user_id' => $host])->save();
            }
        }

        $this->newLine();
        $this->info(($aplicar ? 'Definidos' : 'Definiria').": {$definidos} · sem reunião para inferir: {$semReuniao}");

        return self::SUCCESS;
    }
}
