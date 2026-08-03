<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\WaAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Painel do dono da plataforma (super-admin).
 *
 * DUAS REGRAS que valem para todo método daqui:
 *
 * 1. **Nada de escopo de empresa.** O `CompanyScope` filtra por `company_id` quando há
 *    empresa vinculada; este painel fala de TODAS. Por isso as consultas são feitas em
 *    `DB::table()` (query builder cru, que não conhece scope de model) e cada uma diz
 *    explicitamente de que empresa está falando — nunca "o que sobrar".
 * 2. **Agregado, nunca conteúdo.** O painel devolve contagem, taxa e estado. Nenhuma
 *    rota daqui lê mensagem de cliente de outra empresa.
 *
 * A VPS tem 2 vCPU e o painel é de olhar demorado (fica aberto): tudo passa por cache
 * curto, senão cada F5 varre a tabela de mensagens inteira.
 */
class SuperController extends Controller
{
    /** Janela padrão das métricas de saúde. */
    private const JANELA_HORAS = 24;

    /** Empresas da plataforma, com o tamanho e o pulso de cada uma. */
    public function empresas()
    {
        $dados = Cache::remember('super.empresas', 60, function () {
            $desde = now()->subHours(self::JANELA_HORAS)->timestamp;

            $usuarios = DB::table('users')->selectRaw('company_id, count(*) as n')
                ->groupBy('company_id')->pluck('n', 'company_id');

            $conversas = DB::table('conversations')
                ->selectRaw('company_id, count(*) as n, max(last_message_at) as ultima')
                ->groupBy('company_id')->get()->keyBy('company_id');

            // `ts` é epoch (a coluna cronológica confiável — created_at não é, em
            // conversa importada). Conta entrada e saída separadas: uma empresa que só
            // recebe e não responde é exatamente o que o dono precisa enxergar.
            $msgs = DB::table('messages')->where('ts', '>=', $desde)
                ->selectRaw('company_id, sum(is_out = 0) as entrada, sum(is_out = 1) as saida')
                ->groupBy('company_id')->get()->keyBy('company_id');

            $numeros = DB::table('wa_accounts')->selectRaw('company_id, count(*) as n')
                ->groupBy('company_id')->pluck('n', 'company_id');

            return DB::table('companies')->orderBy('id')->get()->map(fn ($c) => [
                'id' => $c->id,
                'nome' => $c->name,
                'slug' => $c->slug,
                'ativa' => (bool) $c->is_active,
                'criada_em' => $c->created_at,
                'usuarios' => (int) ($usuarios[$c->id] ?? 0),
                'conversas' => (int) ($conversas[$c->id]->n ?? 0),
                'ultima_atividade' => $conversas[$c->id]->ultima ?? null,
                'numeros' => (int) ($numeros[$c->id] ?? 0),
                'msgs_recebidas_24h' => (int) ($msgs[$c->id]->entrada ?? 0),
                'msgs_enviadas_24h' => (int) ($msgs[$c->id]->saida ?? 0),
            ])->values();
        });

        return response()->json(['empresas' => $dados, 'janela_horas' => self::JANELA_HORAS]);
    }

    /**
     * Suspende ou reativa uma empresa. Suspensa, o `SetTenant` recusa toda requisição
     * dos usuários dela com 403 — o dado continua intacto, só o acesso para.
     */
    public function alternarEmpresa(Request $request, Company $company)
    {
        $ativa = $request->boolean('ativa');

        // A própria empresa do super-admin não se suspende: seria trancar a chave dentro
        // de casa (ele perderia o painel que usaria para desfazer).
        if (! $ativa && $company->id === $request->user()->company_id) {
            return response()->json(['erro' => 'Você não pode suspender a sua própria empresa.'], 422);
        }

        $company->forceFill(['is_active' => $ativa])->save();
        Cache::forget('super.empresas');

        return response()->json(['ok' => true, 'ativa' => $ativa]);
    }

    /**
     * Saúde dos números de WhatsApp, atravessando empresas. É a tela que faltava no
     * incidente de 27/07: o número principal levou recusa do WhatsApp por quase 20 horas
     * sem ninguém perceber, e toda primeira resposta a lead novo morria em silêncio.
     */
    public function numeros()
    {
        $dados = Cache::remember('super.numeros', 60, function () {
            $desde = now()->subHours(self::JANELA_HORAS)->timestamp;

            // `messages` não tem `wa_account_id`: o número dono é o da CONVERSA.
            $porConta = DB::table('messages')
                ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('messages.is_out', 1)
                ->where('messages.ts', '>=', $desde)
                ->whereNotNull('conversations.wa_account_id')
                ->selectRaw('conversations.wa_account_id as conta, count(*) as total')
                ->selectRaw("sum(messages.status = 'error') as erro")
                ->selectRaw("sum(messages.status in ('delivered','read')) as entregue")
                ->selectRaw("sum(messages.status = 'read') as lida")
                ->selectRaw("sum(messages.status = 'pending') as pendente")
                ->groupBy('conversations.wa_account_id')->get()->keyBy('conta');

            $ultimaRecusa = DB::table('messages')
                ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('messages.status', 'error')
                ->selectRaw('conversations.wa_account_id as conta, max(messages.updated_at) as quando')
                ->groupBy('conversations.wa_account_id')->pluck('quando', 'conta');

            $empresas = DB::table('companies')->pluck('name', 'id');

            // Quantos números ATIVOS cada empresa tem: com um só, o reenvio automático
            // por número irmão (que já existe no CRM) fica inerte — e o dono precisa
            // saber disso antes do incidente, não durante.
            $ativosPorEmpresa = DB::table('wa_accounts')->where('is_active', 1)
                ->selectRaw('company_id, count(*) as n')->groupBy('company_id')->pluck('n', 'company_id');

            return DB::table('wa_accounts')->orderBy('company_id')->orderBy('id')->get()->map(function ($a) use ($porConta, $ultimaRecusa, $empresas, $ativosPorEmpresa) {
                $m = $porConta[$a->id] ?? null;
                $total = (int) ($m->total ?? 0);
                $erro = (int) ($m->erro ?? 0);

                return [
                    'id' => $a->id,
                    'empresa_id' => $a->company_id,
                    'empresa' => $empresas[$a->company_id] ?? '?',
                    'nome' => $a->name,
                    'telefone' => $a->phone,
                    'canal' => $a->provider === 'cloud' ? 'API oficial' : 'Evolution',
                    'papel' => $a->role,
                    'ativo' => (bool) $a->is_active,
                    'estado' => $a->state,
                    'enviadas_24h' => $total,
                    'entregues_24h' => (int) ($m->entregue ?? 0),
                    'lidas_24h' => (int) ($m->lida ?? 0),
                    'pendentes_24h' => (int) ($m->pendente ?? 0),
                    'recusadas_24h' => $erro,
                    'recusa_pct' => $total > 0 ? round($erro * 100 / $total, 1) : 0.0,
                    'ultima_recusa' => $ultimaRecusa[$a->id] ?? null,
                    'cap_diario' => (int) $a->daily_cap,
                    'enviadas_hoje' => (int) $a->sent_today,
                    'ultimo_envio' => $a->last_sent_at,
                    // Só conta como backup um número IRMÃO: o reenvio nunca cruza empresa.
                    'sem_backup' => (int) ($ativosPorEmpresa[$a->company_id] ?? 0) < 2,
                ];
            })->values();
        });

        return response()->json(['numeros' => $dados, 'janela_horas' => self::JANELA_HORAS]);
    }

    /** Liga/desliga um número (mesma chave que a tela da empresa usa, aqui sem escopo). */
    public function alternarNumero(Request $request, int $conta)
    {
        $acct = WaAccount::withoutGlobalScopes()->findOrFail($conta);
        $acct->forceFill(['is_active' => $request->boolean('ativo')])->save();
        Cache::forget('super.numeros');

        return response()->json(['ok' => true, 'ativo' => (bool) $acct->is_active]);
    }

    /**
     * Saúde da máquina. Tudo aqui é leitura e roda como www-data — nada de pm2 (o daemon
     * é do root): a pergunta "o processo está no ar?" é respondida abrindo a PORTA dele,
     * que é o que de fato importa para o usuário final.
     */
    public function plataforma()
    {
        $dados = Cache::remember('super.plataforma', 30, function () {
            return [
                'servicos' => [
                    $this->porta('Front (Nuxt)', '127.0.0.1', 3000),
                    $this->porta('Reverb (tempo real)', '127.0.0.1', 8080),
                    $this->porta('Evolution (WhatsApp)', ...$this->hostPorta(config('services.evolution.url', 'http://127.0.0.1:8085'))),
                    $this->porta('Banco (MySQL)', config('database.connections.mysql.host', '127.0.0.1'), (int) config('database.connections.mysql.port', 3306)),
                ],
                'agendador' => $this->agendador(),
                'disco' => $this->disco(),
                'memoria' => $this->memoria(),
                'banco_mb' => $this->tamanhoBanco(),
                'migrations_pendentes' => $this->migrationsPendentes(),
                'erros_24h' => $this->errosRecentes(),
                'php' => PHP_VERSION,
                'lido_em' => now()->toIso8601String(),
            ];
        });

        return response()->json($dados);
    }

    /** O serviço responde na porta? (0,8s de espera: o painel não pode travar por isso.) */
    private function porta(string $nome, string $host, int $porta): array
    {
        $inicio = microtime(true);
        $conn = @fsockopen($host, $porta, $errno, $errstr, 0.8);
        $ok = $conn !== false;
        if ($ok) {
            fclose($conn);
        }

        return [
            'nome' => $nome,
            'endereco' => "{$host}:{$porta}",
            'no_ar' => $ok,
            'ms' => $ok ? (int) round((microtime(true) - $inicio) * 1000) : null,
        ];
    }

    /** @return array{0:string,1:int} host e porta de uma URL de serviço. */
    private function hostPorta(string $url): array
    {
        $p = parse_url($url);

        return [$p['host'] ?? '127.0.0.1', (int) ($p['port'] ?? (($p['scheme'] ?? '') === 'https' ? 443 : 80))];
    }

    /**
     * O agendador está vivo? Um batimento gravado a cada minuto responde isso sem
     * depender do pm2 — e agendador parado é o tipo de pane silenciosa que só aparece
     * quando alguém repara que a IA não responde mais.
     */
    private function agendador(): array
    {
        $ultimo = Cache::get('super.heartbeat');
        $segundos = $ultimo ? now()->diffInSeconds($ultimo, true) : null;

        return [
            'ultimo_tick' => $ultimo,
            'ha_segundos' => $segundos,
            // 3 min de folga: o tick é de 1 min, mas a VPS sob carga atrasa sem estar quebrada.
            'ok' => $segundos !== null && $segundos < 180,
        ];
    }

    private function disco(): array
    {
        $total = (float) @disk_total_space('/');
        $livre = (float) @disk_free_space('/');

        return [
            'total_gb' => round($total / 1073741824, 1),
            'livre_gb' => round($livre / 1073741824, 1),
            'usado_pct' => $total > 0 ? round(($total - $livre) * 100 / $total, 1) : null,
        ];
    }

    private function memoria(): array
    {
        $info = @file_get_contents('/proc/meminfo');
        $ler = function (string $chave) use ($info) {
            preg_match("/^{$chave}:\s+(\d+) kB/m", (string) $info, $m);

            return isset($m[1]) ? (int) $m[1] : null;
        };
        $total = $ler('MemTotal');
        $disp = $ler('MemAvailable');

        return [
            'total_mb' => $total ? (int) round($total / 1024) : null,
            'disponivel_mb' => $disp ? (int) round($disp / 1024) : null,
            'usado_pct' => ($total && $disp) ? round(($total - $disp) * 100 / $total, 1) : null,
        ];
    }

    private function tamanhoBanco(): ?float
    {
        try {
            $r = DB::selectOne('select sum(data_length + index_length) as bytes from information_schema.tables where table_schema = database()');

            return $r?->bytes ? round($r->bytes / 1048576, 1) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Migration que está no disco e não no banco = deploy pela metade. */
    private function migrationsPendentes(): array
    {
        try {
            $rodadas = DB::table('migrations')->pluck('migration')->flip();

            return collect(File::files(database_path('migrations')))
                ->map(fn ($f) => $f->getFilenameWithoutExtension())
                ->reject(fn ($nome) => $rodadas->has($nome))
                ->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Erros do laravel.log nas últimas 24h, agrupados pela primeira linha (a mensagem),
     * do mais frequente para o menos. Lê só o FIM do arquivo: em produção ele passa de
     * centenas de MB e carregar tudo derrubaria o PHP-FPM.
     */
    private function errosRecentes(int $limite = 8): array
    {
        $caminho = storage_path('logs/laravel.log');
        if (! is_readable($caminho)) {
            return [];
        }

        $tamanho = filesize($caminho);
        $bytes = 512 * 1024;
        $fh = fopen($caminho, 'r');
        if (! $fh) {
            return [];
        }
        if ($tamanho > $bytes) {
            fseek($fh, -$bytes, SEEK_END);
            fgets($fh); // descarta a linha cortada ao meio
        }

        $desde = now()->subHours(self::JANELA_HORAS);
        $contagem = [];
        while (($linha = fgets($fh)) !== false) {
            if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*?\.(ERROR|CRITICAL|EMERGENCY): (.+)$/', $linha, $m)) {
                continue;
            }
            try {
                if (\Illuminate\Support\Carbon::parse($m[1])->lt($desde)) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            // Corta o "in /caminho/arquivo.php:123" e o stack trace: o que agrupa é a frase.
            $msg = trim(preg_split('/ in \/|\s\{"exception"/', $m[3])[0]);
            $chave = mb_substr($msg, 0, 160);
            $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
        }
        fclose($fh);

        arsort($contagem);

        return collect($contagem)->take($limite)
            ->map(fn ($n, $msg) => ['mensagem' => $msg, 'vezes' => $n])
            ->values()->all();
    }
}
