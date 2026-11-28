<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Services\AlarmeDaSala;
use App\Services\GoogleCalendarService;
use App\Support\Evolution;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Alarme de reunião ENQUANTO ela acontece: lead esperando e ninguém nosso na sala.
 *
 * Em 12/08/2026 um lead que já tinha dito que ia fechar ficou sozinho na sala duas
 * reuniões seguidas. A primeira versão deste tick só alertava com o lead DENTRO da sala,
 * e isso deixava passar o caso mais comum: no Meet, o convidado fica na SALA DE ESPERA
 * ("pedir para participar") até alguém da equipe aceitar. Com ninguém nosso lá, a sala
 * nem abre, a API do Meet não devolve participante nenhum e o tick concluía "ninguém
 * entrou ainda, não é falta nossa". Foi assim em 25/11 (Viny: "fiquei 5 minutos e
 * ninguém apareceu") e 27/11 (José Filho: "faz horas que peço, kd vcs"). E o aviso virava
 * uma tarefa `followup`, que não aparece no quadro: ninguém via a tempo.
 *
 * Por isso agora são três sinais, todos pelo WhatsApp da equipe (AlarmeDaSala):
 *  1. o lead ESCREVE que está esperando ("pedi para entrar", "ninguém aceitou"): na hora;
 *  2. a reunião começou há `--depois` minutos e ninguém da equipe está na sala, com ou sem
 *     o lead dentro (a sala de espera não aparece na API, então "ninguém nosso" é o sinal);
 *     repete a cada 5 min, no máximo 3 vezes;
 *  3. anfitrião sem Google conectado: o CRM fica cego para a sala. Avisa nas 24 h antes da
 *     reunião, no início dela e uma vez por dia para quem está como anfitrião ativo.
 *
 * Só reunião com lead (conversation_id): reunião interna não tem quem esperar.
 */
class MeetingsNoShowTick extends Command
{
    protected $signature = 'meetings:noshow-tick
        {--depois=2 : minutos após o início a partir dos quais a falta da equipe na sala vira alarme}
        {--ate=30 : minutos após o início até quando vale alertar}
        {--simular : mostra o que seria enviado, sem mandar nada}';

    protected $description = 'Alarme no WhatsApp da equipe: lead esperando na sala, ninguém nosso na sala, anfitrião sem Google';

    /** Mensagem do lead conta se chegou de 15 min antes do início até o fim da reunião. */
    private const MENSAGEM_ANTES_MIN = 15;

    public function handle(GoogleCalendarService $google, AlarmeDaSala $alarme): int
    {
        $depois = max(1, (int) $this->option('depois'));
        $ate = max($depois + 1, (int) $this->option('ate'));
        $alarme->simular((bool) $this->option('simular'));
        $tenancy = app(Tenancy::class);

        $this->anfitrioesSemGoogle($alarme);

        $reunioes = Meeting::withoutGlobalScopes()
            ->whereNull('cancelled_at')
            ->whereNotNull('conversation_id')
            ->whereBetween('starts_at', [now()->subMinutes(max($ate, 90)), now()->addMinutes(self::MENSAGEM_ANTES_MIN)])
            ->get();

        foreach ($reunioes as $m) {
            $tenancy->set($m->company_id);
            try {
                $this->mensagemDoLead($m, $alarme);

                $minutos = (int) floor(Carbon::parse($m->starts_at)->diffInSeconds(now(), false) / 60);
                if ($minutos >= $depois && $minutos <= $ate) {
                    $this->sala($m, $minutos, $depois, $google, $alarme);
                }
            } finally {
                $tenancy->forget();
            }
        }

        foreach ($alarme->disparos as $d) {
            $this->warn(($this->option('simular') ? '[simulação] ' : '')."{$d['chave']} → ".count($d['destinos'])." número(s)\n{$d['texto']}\n");
        }

        return self::SUCCESS;
    }

    /** Sinal 1: o lead escreveu que está esperando. */
    private function mensagemDoLead(Meeting $m, AlarmeDaSala $alarme): void
    {
        $ini = (int) $m->startedTs() - self::MENSAGEM_ANTES_MIN * 60;
        $fim = (int) ($m->endedTs() ?: $m->startedTs() + 3600);

        // Só mensagem dos últimos 30 min: a reunião de 1 h atrás não alarma de novo por
        // causa de uma mensagem velha (a trava também segura, mas o cache pode ter sido limpo).
        $msg = Message::withoutGlobalScopes()
            ->where('conversation_id', $m->conversation_id)
            ->where('is_out', false)
            ->whereBetween('ts', [max($ini, time() - 30 * 60), $fim])
            ->orderByDesc('ts')->limit(10)->get()
            ->first(fn (Message $x) => AlarmeDaSala::pareceEsperando($x->text ?: $x->transcript));

        if (! $msg) {
            return;
        }

        // Uma por mensagem; e, para o lead que manda 5 seguidas, no máximo uma a cada 3 min.
        $recente = "alarme-sala:{$m->company_id}:msg-recente:{$m->id}";
        if (Cache::has($recente)) {
            return;
        }

        $texto = "🔴 *Lead esperando na sala*\n\n"
            .$this->nomeDoLead($m).' escreveu às '.Carbon::createFromTimestamp((int) $msg->ts, config('app.timezone'))->format('H:i')
            .': "'.mb_substr(trim((string) ($msg->text ?: $msg->transcript)), 0, 200)."\"\n"
            .'Reunião das '.Carbon::parse($m->starts_at)->format('H:i').'. Entre e aceite o lead agora.'."\n"
            .$this->rodape($m);

        if ($alarme->enviar((int) $m->company_id, "msg:{$msg->id}", $texto, 24 * 60) && ! $this->option('simular')) {
            Cache::put($recente, 1, now()->addMinutes(3));
            Evolution::log('meeting.lead_esperando', ['meeting_id' => $m->id, 'message_id' => $msg->id], 'warning');
        }
    }

    /** Sinais 2 e 3: quem está na sala, pelo Meet. */
    private function sala(Meeting $m, int $minutos, int $depois, GoogleCalendarService $google, AlarmeDaSala $alarme): void
    {
        $host = $m->user_id ? User::withoutGlobalScopes()->find($m->user_id) : null;
        $inicio = Carbon::parse($m->starts_at)->format('H:i');

        if (! $host?->google_refresh_token) {
            $alarme->enviar((int) $m->company_id, "cego:{$m->id}",
                "⚠️ *Reunião sem como ver a sala*\n\n"
                .'A reunião com '.$this->nomeDoLead($m)." começou às {$inicio} e o anfitrião (".($host?->name ?? 'ninguém').') está sem Google conectado. '
                ."O CRM não consegue ver se alguém da equipe entrou nem se o lead está esperando. Confirme agora.\n"
                .$this->rodape($m),
                24 * 60);

            return;
        }

        // Depois da 3ª rodada, ou com alguém nosso já visto na sala, não pergunta mais ao Meet.
        $rodada = intdiv($minutos - $depois, 5);
        $equipeViu = "alarme-sala:{$m->company_id}:equipe-na-sala:{$m->id}";
        if ($rodada > 2 || Cache::has($equipeViu)) {
            return;
        }

        if (! preg_match('~([a-z]{3}-[a-z]{4}-[a-z]{3})~', (string) $m->meet_link, $mm)) {
            return;
        }

        try {
            $res = $google->conferenceAttendance($host, $mm[1], Carbon::parse($m->starts_at), now());
        } catch (\Throwable $e) {
            Evolution::log('meeting.noshow_sem_leitura', ['meeting_id' => $m->id, 'erro' => mb_substr($e->getMessage(), 0, 200)], 'warning');

            return;
        }

        // O time é reconhecido por nome de exibição: a Meet API não devolve e-mail.
        // Nome novo na equipe precisa entrar em CRM_TEAM_DISPLAY_NAMES, senão o alarme
        // dispara com a pessoa dentro da sala (mesma armadilha da apuração de presença).
        $timeNomes = collect((array) config('services.crm.team_display_names', []))
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))->filter();
        $ehNosso = fn ($p) => $timeNomes->contains(fn ($t) => str_contains(mb_strtolower((string) ($p['name'] ?? '')), $t));

        $participantes = collect($res['participants'] ?? [])->reject(fn ($p) => ! empty($p['bot']));
        if ($participantes->contains($ehNosso)) {
            Cache::put($equipeViu, 1, now()->addHours(2));

            return;
        }

        $lead = $participantes->reject($ehNosso);
        if ($lead->isNotEmpty()) {
            $quem = $lead->pluck('name')->implode(', ');
            $titulo = "🔴 Cliente sozinho na sala — {$m->title}";
            $texto = "🔴 *Lead sozinho na sala*\n\n{$quem} está na reunião das {$inicio} e ninguém da equipe entrou.\n".$this->rodape($m);
        } else {
            $quem = null;
            $titulo = "🔴 Ninguém da equipe na sala — {$m->title}";
            $texto = "🔴 *Ninguém da equipe na sala*\n\nA reunião com ".$this->nomeDoLead($m)." começou às {$inicio} e ninguém nosso entrou. "
                ."Se o lead pedir para entrar, ninguém aceita.\n".$this->rodape($m);
        }

        if (! $alarme->enviar((int) $m->company_id, "sala:{$m->id}:{$rodada}", $texto, 120) || $this->option('simular')) {
            return;
        }

        Evolution::log('meeting.noshow_equipe', [
            'meeting_id' => $m->id, 'na_sala' => $quem, 'host' => $host->name, 'rodada' => $rodada,
        ], 'warning');

        // A tarefa fica na ficha do lead: é o rastro de que a sala ficou sem ninguém.
        if (! Task::where('title', $titulo)->where('column', '!=', 'done')->exists()) {
            Task::create([
                'conversation_id' => $m->conversation_id,
                'title' => $titulo,
                'description' => "A reunião começou às {$inicio}"
                    .($quem ? " e {$quem} está esperando." : ' e ninguém da equipe entrou na sala.')
                    .' Entre agora ou avise o cliente pelo WhatsApp.',
                'client' => $m->conversation?->name,
                'type' => 'followup',
                'priority' => 'alta',
                'column' => 'todo',
                'position' => (Task::where('column', 'todo')->min('position') ?? 0) - 1,
                'starts_at' => now(),
                'due' => now(),
            ]);
        }
    }

    /**
     * Sinal 3 fora da reunião: anfitrião sem Google.
     *  - reunião com lead nas próximas 24 h e anfitrião sem Google: avisa uma vez;
     *  - anfitrião ativo (recebe reunião) sem Google: uma vez por dia, das 8h às 20h.
     */
    private function anfitrioesSemGoogle(AlarmeDaSala $alarme): void
    {
        $proximas = Meeting::withoutGlobalScopes()
            ->whereNull('cancelled_at')
            ->whereNotNull('conversation_id')
            ->whereBetween('starts_at', [now(), now()->addDay()])
            ->get();

        foreach ($proximas as $m) {
            $host = $m->user_id ? User::withoutGlobalScopes()->find($m->user_id) : null;
            if ($host?->google_refresh_token) {
                continue;
            }
            $alarme->enviar((int) $m->company_id, "antes:{$m->id}",
                "⚠️ *Reunião com anfitrião sem Google*\n\n"
                .'A reunião com '.$this->nomeDoLead($m).' de '.Carbon::parse($m->starts_at)->format('d/m').' às '.Carbon::parse($m->starts_at)->format('H:i')
                .' está com '.($host?->name ?? 'ninguém').', sem Google conectado. '
                ."O CRM não vai ver a sala nem avisar se o lead ficar esperando. Reconecte o Google (Agenda > Conectar Google) ou combine quem entra.\n"
                .$this->rodape($m),
                3 * 24 * 60);
        }

        $hora = (int) now()->format('G');
        if ($hora < 8 || $hora >= 20) {
            return;
        }

        $semGoogle = User::withoutGlobalScopes()
            ->where('is_host', true)->where('agenda_ativa', true)->whereNull('google_refresh_token')
            ->get()->groupBy('company_id');

        foreach ($semGoogle as $companyId => $usuarios) {
            $nomes = $usuarios->pluck('name')->implode(', ');
            $varios = $usuarios->count() > 1;
            $alarme->enviar((int) $companyId, 'anfitriao-sem-google:'.now()->format('Y-m-d'),
                "⚠️ *Anfitrião sem Google conectado*\n\n"
                .$nomes.($varios ? ' estão como anfitriões ativos' : ' está como anfitrião ativo')
                .' mas sem Google conectado: '.($varios ? 'não recebem' : 'não recebe').' reunião nova e o CRM não vê a sala. '
                .'Para reconectar: entrar no CRM com a própria conta > Agenda > Conectar Google.',
                24 * 60);
        }
    }

    private function nomeDoLead(Meeting $m): string
    {
        return trim((string) ($m->conversation?->name ?: $m->title)) ?: 'o lead';
    }

    private function rodape(Meeting $m): string
    {
        return "\nSala: {$m->meet_link}"
            .($m->phone ? "\nWhatsApp do lead: {$m->phone}" : '');
    }
}
