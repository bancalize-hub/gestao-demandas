<?php

namespace App\Console\Commands;

use App\Models\SdrAgent;
use App\Services\Sdr\Sdr;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * As ferramentas do agente SDR. É o ÚNICO comando que ele pode rodar (o --allowedTools só
 * libera `php artisan sdr …`), então tudo que ele enxerga ou faz nas conversas passa por aqui
 * — e pelas travas de {@see Sdr::bloqueio()}.
 *
 * Empresa, simulação e sessão vêm do ambiente que o turno monta, nunca de argumento: o agente
 * não escolhe em que empresa mexe nem desliga a simulação sozinho.
 */
class SdrFerramenta extends Command
{
    protected $signature = 'sdr
        {acao? : fila | conversa | rascunhar | enviar | template | lembrar | pausar | anotar | avisar | diario | aprender | esquecer | voltar-em}
        {alvo? : id da conversa (ou minutos, no voltar-em; número, no esquecer)}
        {valor? : horas (lembrar)}
        {--texto= : enviar / lembrar / anotar / avisar / aprender}
        {--instrucao= : rascunhar: o que a mensagem tem que fazer}
        {--motivo= : por que (obrigatório no envio)}
        {--tipo= : template: retomada | reuniao}
        {--assunto= : template retomada: o assunto, até 60 caracteres}
        {--dias=7 : pausar}
        {--lead= : avisar: id da conversa}
        {--todos : fila: mostra todos}';

    protected $description = 'Ferramentas do agente SDR (ler a fila e as conversas, mandar mensagem dentro das travas)';

    public function handle(Tenancy $tenancy): int
    {
        $empresa = (int) (getenv('GESTOR_EMPRESA') ?: 0);
        if ($empresa <= 0) {
            $this->error('GESTOR_EMPRESA não definido — esta ferramenta só roda dentro de um turno do SDR.');

            return self::FAILURE;
        }

        return $tenancy->run($empresa, function () {
            $sdr = new Sdr(SdrAgent::atual(), getenv('GESTOR_SIMULAR') === '1', (int) getenv('GESTOR_SESSAO') ?: null);
            $acao = (string) $this->argument('acao');
            $id = (int) $this->argument('alvo');
            $texto = (string) $this->option('texto');
            $motivo = trim((string) $this->option('motivo'));

            if (in_array($acao, ['enviar', 'template'], true) && $motivo === '') {
                $this->line('Falta --motivo="…": todo envio diz por que este lead, agora.');

                return self::FAILURE;
            }
            if (in_array($acao, ['conversa', 'rascunhar', 'enviar', 'template', 'lembrar', 'pausar', 'anotar'], true) && $id <= 0) {
                $this->line('Falta o id da conversa: `php artisan sdr '.$acao.' <id> …`.');

                return self::FAILURE;
            }

            try {
                $saida = match ($acao) {
                    'fila' => $sdr->fila((bool) $this->option('todos')),
                    'conversa' => $sdr->ficha($id),
                    'rascunhar' => $sdr->rascunhar($id, (string) $this->option('instrucao')),
                    'enviar' => $sdr->enviar($id, $texto, $motivo),
                    'template' => $sdr->template($id, (string) $this->option('tipo'), (string) $this->option('assunto'), $motivo),
                    'lembrar' => $sdr->lembrar($id, (float) str_replace(',', '.', (string) $this->argument('valor')), $texto),
                    'pausar' => $sdr->pausar($id, (int) $this->option('dias'), $motivo ?: $texto),
                    'anotar' => $sdr->anotar($id, $texto),
                    'avisar' => $sdr->avisar($texto, $this->option('lead') ? (int) $this->option('lead') : null),
                    'diario' => $sdr->diario(),
                    'aprender' => $sdr->aprender($texto),
                    'esquecer' => $sdr->esquecer($id),
                    'voltar-em' => $sdr->voltarEm($id, $motivo ?: 'sem motivo'),
                    default => $this->ajuda(),
                };
            } catch (\Throwable $e) {
                $saida = 'ERRO: '.$e->getMessage();
            }

            $this->line($saida);

            return self::SUCCESS;
        });
    }

    private function ajuda(): string
    {
        return <<<'TXT'
php artisan sdr fila [--todos]                         quem está no caminho até a call, por urgência
php artisan sdr conversa <id>                           ficha + últimas mensagens + se pode enviar
php artisan sdr rascunhar <id> --instrucao="…"          texto do atendimento da empresa (agenda real), sem enviar
php artisan sdr enviar <id> --texto="…" --motivo="…"    envia texto livre
php artisan sdr template <id> --tipo=retomada --assunto="…" --motivo="…"   janela fechada (oficial)
php artisan sdr template <id> --tipo=reuniao --motivo="…"                   lembrete da reunião, janela fechada
php artisan sdr lembrar <id> <horas> --texto="…"         volto neste lead em N horas
php artisan sdr pausar <id> --dias=N --motivo="…"        deixo quieto por N dias
php artisan sdr anotar <id> --texto="…"                  nota na ficha para o time
php artisan sdr avisar --texto="…" [--lead=<id>]         WhatsApp para o dono
php artisan sdr diario                                   o que mandei em 72h e o que veio depois
php artisan sdr aprender --texto="…" | esquecer <n>      memória entre turnos
php artisan sdr voltar-em <minutos> --motivo="…"         próximo turno (30–240)
TXT;
    }
}
