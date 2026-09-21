<?php

namespace App\Services;

use App\Exceptions\GoogleDesconectado;
use App\Models\User;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\ConferenceSolutionKey;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleCalendarService
{
    /**
     * Cliente base configurado com as credenciais do projeto.
     * access_type=offline + prompt=consent garantem o refresh token na 1ª conexão.
     */
    private function baseClient(): GoogleClient
    {
        $client = new GoogleClient;
        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));
        $client->setRedirectUri((string) config('services.google.redirect'));
        $client->setScopes([
            GoogleCalendar::CALENDAR_EVENTS,
            GoogleCalendar::CALENDAR_READONLY,
            'https://www.googleapis.com/auth/contacts',
            // Meet REST API: ler participantes/transcrição/resumo das reuniões.
            \Google\Service\Meet::MEETINGS_SPACE_READONLY,
            // Meet REST API (configuração da sala): ligar gravação/transcrição AUTOMÁTICAS.
            // Escopo novo ⇒ o usuário precisa Desconectar→Conectar o Google de novo.
            \Google\Service\Meet::MEETINGS_SPACE_SETTINGS,
            // Gmail (somente leitura): ler os relatórios de reunião do read.ai por e-mail.
            \Google\Service\Gmail::GMAIL_READONLY,
            // Drive (somente leitura): baixar a GRAVAÇÃO da reunião p/ o player da ficha.
            // Escopo novo ⇒ o usuário precisa Desconectar→Conectar o Google de novo.
            \Google\Service\Drive::DRIVE_READONLY,
            'openid',
            'email',
        ]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    /** URL para iniciar o consentimento OAuth. `state` identifica o usuário no callback. */
    public function authUrl(string $state): string
    {
        $client = $this->baseClient();
        $client->setState($state);
        // Força a tela de consentimento (garante refresh_token e a concessão de escopos
        // recém-adicionados, como o Gmail) e mantém os escopos já concedidos (incremental).
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client->createAuthUrl();
    }

    /**
     * Troca o `code` do callback por tokens e devolve o pacote pronto para salvar.
     *
     * @return array{access_token:string, refresh_token:?string, expires_at:Carbon, email:?string}
     */
    public function exchangeCode(string $code): array
    {
        $client = $this->baseClient();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new RuntimeException('Google OAuth: '.($token['error_description'] ?? $token['error']));
        }

        // E-mail da conta conectada (via id_token, se veio).
        $email = null;
        if (! empty($token['id_token'])) {
            $payload = $client->verifyIdToken($token['id_token']);
            $email = $payload['email'] ?? null;
        }

        return [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at' => Carbon::now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'email' => $email,
        ];
    }

    /**
     * Client autenticado para um usuário, renovando o access token se expirou
     * e persistindo o novo token no banco.
     */
    private function clientFor(User $user): GoogleClient
    {
        if (! $user->hasGoogle()) {
            throw new RuntimeException('Usuário sem conta Google vinculada.');
        }

        $client = $this->baseClient();
        $client->setAccessToken([
            'access_token' => $user->google_access_token,
            'refresh_token' => $user->google_refresh_token,
            'expires_in' => optional($user->google_token_expires_at)->diffInSeconds(now(), false) * -1,
            'created' => now()->timestamp,
        ]);

        if ($client->isAccessTokenExpired()) {
            $new = $client->fetchAccessTokenWithRefreshToken($user->google_refresh_token);
            if (isset($new['error'])) {
                // `invalid_grant` = o refresh token morreu (usuário revogou o acesso, ou o
                // app está no modo "Testing" do Google Cloud, onde ele caduca em 7 dias).
                // Tentar de novo nunca vai funcionar: apaga a credencial para o sistema
                // inteiro voltar a mostrar "Conectar Google" em vez de estourar toda vez.
                if ($new['error'] === 'invalid_grant') {
                    $user->forceFill([
                        'google_access_token' => null,
                        'google_refresh_token' => null,
                        'google_token_expires_at' => null,
                    ])->save();

                    throw new GoogleDesconectado((string) ($new['error_description'] ?? ''));
                }

                throw new RuntimeException('Falha ao renovar token Google: '.($new['error_description'] ?? $new['error']));
            }
            $user->google_access_token = $new['access_token'];
            $user->google_token_expires_at = Carbon::now()->addSeconds((int) ($new['expires_in'] ?? 3600));
            // O Google só reenvia refresh_token às vezes; preserva o existente.
            if (! empty($new['refresh_token'])) {
                $user->google_refresh_token = $new['refresh_token'];
            }
            $user->save();
        }

        return $client;
    }

    private function calendar(User $user): GoogleCalendar
    {
        return new GoogleCalendar($this->clientFor($user));
    }

    private function calendarId(User $user): string
    {
        return $user->google_calendar_id ?: 'primary';
    }

    /**
     * Cor fixa da agenda de um usuário na tela — a mesma no seletor de agendas, na legenda
     * e no card do evento. Vem do id (não da posição na lista) para não trocar de cor quando
     * alguém entra ou sai da empresa.
     */
    public static function corDaAgenda(int $userId): string
    {
        $cores = ['#7C6CF5', '#53BDEB', '#FFB443', '#2FC98A', '#F06AA0', '#8FD14F', '#FF8A5B', '#4FD1C5'];

        return $cores[$userId % count($cores)];
    }

    /** Serviço da Meet REST API autenticado para o usuário. */
    public function meet(User $user): \Google\Service\Meet
    {
        return new \Google\Service\Meet($this->clientFor($user));
    }

    /**
     * Liga a GRAVAÇÃO (e a transcrição) automáticas na sala do Meet — a call começa a
     * gravar sozinha quando o anfitrião entra, ninguém precisa lembrar de apertar "Gravar".
     *
     * A configuração fica NA SALA: remarcar o evento mantém o mesmo Meet e o ajuste
     * continua valendo. Best-effort de propósito — só funciona quando o dono da sala é
     * conta Workspace com permissão de gravar e com o escopo meetings.space.settings
     * concedido; convite criado por terceiros ou conta ainda sem reconectar falha em
     * silêncio (melhor reunião sem gravação do que agendamento quebrado).
     */
    public function enableAutoRecording(User $user, ?string $meetLink): bool
    {
        if (! preg_match('#meet\.google\.com/([a-z]{3,4}-[a-z]{3,4}-[a-z]{3,4})#i', (string) $meetLink, $m)) {
            return false;
        }

        try {
            $meet = $this->meet($user);
            // get() aceita o código do link; o patch exige o nome canônico (spaces/xxx).
            $space = $meet->spaces->get('spaces/'.strtolower($m[1]));

            $rec = new \Google\Service\Meet\RecordingConfig;
            $rec->setAutoRecordingGeneration('ON');
            $tr = new \Google\Service\Meet\TranscriptionConfig;
            $tr->setAutoTranscriptionGeneration('ON');
            $art = new \Google\Service\Meet\ArtifactConfig;
            $art->setRecordingConfig($rec);
            $art->setTranscriptionConfig($tr);
            $config = new \Google\Service\Meet\SpaceConfig;
            $config->setArtifactConfig($art);
            $body = new \Google\Service\Meet\Space;
            $body->setConfig($config);

            $meet->spaces->patch($space->getName(), $body, [
                'updateMask' => 'config.artifactConfig.recordingConfig.autoRecordingGeneration,'
                    .'config.artifactConfig.transcriptionConfig.autoTranscriptionGeneration',
            ]);

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::info('meet.auto_gravacao_falhou', [
                'user_id' => $user->id,
                'meet' => $meetLink,
                'erro' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Serviço do Gmail (leitura) autenticado para o usuário. */
    public function gmail(User $user): \Google\Service\Gmail
    {
        return new \Google\Service\Gmail($this->clientFor($user));
    }

    /** Bots de anotação que entram na sala — não contam como "o cliente compareceu". */
    private function isNotetakerBot(string $name): bool
    {
        return (bool) preg_match('/read\.?ai|otter|fireflies|fathom|notetaker|meeting notes|tl;dv|tldv/i', $name);
    }

    /**
     * Apura a presença real numa reunião do Meet pelo código do link (ex.: "abc-defg-hij").
     * Devolve os participantes com o tempo (min) em sala, separando o bot de anotação dos humanos.
     *
     * UMA reunião gera VÁRIOS conference records: cada vez que a sala esvazia e alguém entra de
     * novo, o Google abre outro registro — e os primeiros costumam ser tentativas de 2 segundos,
     * com ZERO participantes (o cliente batendo na porta antes de ser admitido). Ler só o registro
     * mais próximo do horário marcado era exatamente pegar essas tentativas vazias e concluir
     * "ninguém veio" numa reunião de 1 hora que aconteceu (foi o que marcou reunião realizada como
     * falta). Por isso somamos TODOS os registros que caem na janela da reunião.
     *
     * @return array{found:bool, participants:array<int,array{name:string,minutes:int,bot:bool}>}
     */
    public function conferenceAttendance(User $user, string $meetingCode, Carbon $start, ?Carbon $end = null): array
    {
        $meet = $this->meet($user);
        $resp = $meet->conferenceRecords->listConferenceRecords([
            'filter' => 'space.meeting_code="'.$meetingCode.'"',
            'pageSize' => 20,
        ]);
        $records = $resp->getConferenceRecords() ?? [];
        if (! $records) {
            return ['found' => false, 'participants' => []];
        }

        // Janela da reunião, com folga para quem entra adiantado ou o encontro furar o horário.
        // Serve também para separar reuniões DIFERENTES que reusaram o mesmo link no mesmo dia.
        $janelaIni = $start->copy()->subMinutes(20);
        $janelaFim = ($end ? $end->copy() : $start->copy()->addHour())->addMinutes(90);

        $selecionados = [];
        foreach ($records as $rec) {
            $rs = $rec->getStartTime() ? Carbon::parse($rec->getStartTime()) : null;
            if (! $rs) {
                continue;
            }
            $re = $rec->getEndTime() ? Carbon::parse($rec->getEndTime()) : Carbon::now();
            if ($rs->lt($janelaFim) && $re->gt($janelaIni)) {
                $selecionados[] = $rec;
            }
        }

        // Nenhum registro na janela: cai no comportamento antigo (o mais próximo em até 6h),
        // para reunião que começou muito fora do horário ainda ser apurada.
        if (! $selecionados) {
            $best = null;
            $bestDiff = PHP_INT_MAX;
            foreach ($records as $rec) {
                $rs = $rec->getStartTime() ? Carbon::parse($rec->getStartTime()) : null;
                $diff = $rs ? abs($rs->diffInSeconds($start)) : PHP_INT_MAX;
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $best = $rec;
                }
            }
            if (! $best || $bestDiff > 6 * 3600) {
                return ['found' => false, 'participants' => []];
            }
            $selecionados = [$best];
        }

        // Mesmo participante em registros diferentes = ele saiu e voltou: soma o tempo das sessões.
        $porNome = [];
        foreach ($selecionados as $rec) {
            $parts = $meet->conferenceRecords_participants
                ->listConferenceRecordsParticipants($rec->getName(), ['pageSize' => 100])
                ->getParticipants() ?? [];

            foreach ($parts as $p) {
                $name = $p->getSignedinUser()?->getDisplayName()
                    ?: $p->getAnonymousUser()?->getDisplayName()
                    ?: ($p->getPhoneUser() ? 'Telefone' : 'Desconhecido');
                $in = $p->getEarliestStartTime() ? Carbon::parse($p->getEarliestStartTime()) : null;
                $out = $p->getLatestEndTime() ? Carbon::parse($p->getLatestEndTime()) : Carbon::now();
                $minutes = ($in && $out) ? (int) round($in->diffInSeconds($out) / 60) : 0;

                $porNome[$name] = ($porNome[$name] ?? 0) + max(0, $minutes);
            }
        }

        $out = [];
        foreach ($porNome as $name => $minutes) {
            $out[] = ['name' => $name, 'minutes' => $minutes, 'bot' => $this->isNotetakerBot($name)];
        }

        return ['found' => true, 'participants' => $out];
    }

    /**
     * Transcrição e gravação nativas do Meet (só existem em conta Workspace).
     * Junta as falas de TODOS os registros da janela da reunião (mesma regra da presença:
     * sala que esvazia e reabre gera outro conference record) em "Fulano: fala" por linha.
     *
     * @return array{transcript: ?string, recording_link: ?string}
     */
    public function conferenceArtifacts(User $user, string $meetingCode, Carbon $start, ?Carbon $end = null, bool $incluirTranscricao = true): array
    {
        $meet = $this->meet($user);
        $records = $meet->conferenceRecords->listConferenceRecords([
            'filter' => 'space.meeting_code="'.$meetingCode.'"',
            'pageSize' => 20,
        ])->getConferenceRecords() ?? [];

        $janelaIni = $start->copy()->subMinutes(20);
        $janelaFim = ($end ? $end->copy() : $start->copy()->addHour())->addMinutes(90);

        $lines = [];
        $recordingFileId = null;
        $recordingLink = null;
        foreach ($records as $rec) {
            $rs = $rec->getStartTime() ? Carbon::parse($rec->getStartTime()) : null;
            $re = $rec->getEndTime() ? Carbon::parse($rec->getEndTime()) : Carbon::now();
            if (! $rs || ! ($rs->lt($janelaFim) && $re->gt($janelaIni))) {
                continue;
            }

            // Backfill só de gravação (o vídeo fica pronto DEPOIS da transcrição): pula o
            // trabalho pesado de baixar as falas de novo.
            if (! $incluirTranscricao) {
                foreach ($meet->conferenceRecords_recordings->listConferenceRecordsRecordings($rec->getName())->getRecordings() ?? [] as $r) {
                    $file = $r->getDriveDestination()?->getFile();
                    $uri = $r->getDriveDestination()?->getExportUri();
                    $recordingFileId ??= $file;
                    $recordingLink ??= $uri ?: ($file ? "https://drive.google.com/file/d/{$file}/view" : null);
                }

                continue;
            }

            // As falas apontam para o resource name do participante — resolve para nome de exibição.
            $nomes = [];
            $pageToken = null;
            do {
                $pl = $meet->conferenceRecords_participants->listConferenceRecordsParticipants(
                    $rec->getName(),
                    array_filter(['pageSize' => 100, 'pageToken' => $pageToken]),
                );
                foreach ($pl->getParticipants() ?? [] as $p) {
                    $nomes[$p->getName()] = $p->getSignedinUser()?->getDisplayName()
                        ?: $p->getAnonymousUser()?->getDisplayName()
                        ?: ($p->getPhoneUser() ? 'Telefone' : 'Participante');
                }
                $pageToken = $pl->getNextPageToken();
            } while ($pageToken);

            foreach ($meet->conferenceRecords_transcripts->listConferenceRecordsTranscripts($rec->getName())->getTranscripts() ?? [] as $t) {
                $pageToken = null;
                do {
                    $el = $meet->conferenceRecords_transcripts_entries->listConferenceRecordsTranscriptsEntries(
                        $t->getName(),
                        array_filter(['pageSize' => 1000, 'pageToken' => $pageToken]),
                    );
                    foreach ($el->getTranscriptEntries() ?? [] as $e) {
                        $texto = trim((string) $e->getText());
                        if ($texto !== '') {
                            $lines[] = ($nomes[$e->getParticipant()] ?? 'Participante').': '.$texto;
                        }
                        if (count($lines) >= 4000) { // trava: reunião muito longa não estoura memória/prompt
                            break 3;
                        }
                    }
                    $pageToken = $el->getNextPageToken();
                } while ($pageToken);
            }

            foreach ($meet->conferenceRecords_recordings->listConferenceRecordsRecordings($rec->getName())->getRecordings() ?? [] as $r) {
                $file = $r->getDriveDestination()?->getFile();
                $uri = $r->getDriveDestination()?->getExportUri();
                $recordingFileId ??= $file;
                $recordingLink ??= $uri ?: ($file ? "https://drive.google.com/file/d/{$file}/view" : null);
            }
        }

        return [
            'transcript' => $lines ? implode("\n", $lines) : null,
            'recording_file_id' => $recordingFileId,
            'recording_link' => $recordingLink,
        ];
    }

    /**
     * Baixa um arquivo do Drive autenticado como o usuário (streaming, com suporte a Range —
     * é o que deixa o <video> da ficha pular para qualquer ponto da gravação).
     */
    public function driveDownload(User $user, string $fileId, ?string $range = null): \Psr\Http\Message\ResponseInterface
    {
        $http = $this->clientFor($user)->authorize();

        return $http->request('GET', "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media&supportsAllDrives=true", [
            'headers' => array_filter(['Range' => $range]),
            'stream' => true,
            'http_errors' => false,
        ]);
    }

    /**
     * Eventos do usuário num intervalo, normalizados para o front.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listEvents(User $user, Carbon $from, Carbon $to): array
    {
        $events = $this->calendar($user)->events->listEvents($this->calendarId($user), [
            'timeMin' => $from->toRfc3339String(),
            'timeMax' => $to->toRfc3339String(),
            'singleEvents' => true,
            'orderBy' => 'startTime',
            'maxResults' => 250,
        ]);

        $out = [];
        foreach ($events->getItems() as $e) {
            $out[] = $this->normalize($e);
        }

        // Enriquece com dados da reunião do CRM: presença confirmada (destaque na agenda) e
        // o cliente vinculado (p/ abrir a ficha ao clicar no card).
        $ids = array_filter(array_column($out, 'id'));
        if ($ids) {
            $meetings = \App\Models\Meeting::whereIn('google_event_id', $ids)
                ->with('conversation:id,slug,name')
                ->get()
                ->keyBy('google_event_id');
            foreach ($out as &$ev) {
                $m = $meetings->get($ev['id']);
                $ev['attended'] = (bool) ($m?->attended);
                // No-show: presença JÁ apurada, com MEDIÇÃO de verdade (attended_minutes não nulo),
                // e o cliente não ficou o mínimo → vermelho na agenda. Sem medição não há acusação:
                // o card fica neutro em vez de dizer "não compareceu" sobre uma reunião que
                // aconteceu e o Google simplesmente não relatou.
                $ev['no_show'] = (bool) ($m && $m->attendance_checked_at !== null
                    && $m->attended_minutes !== null && ! $m->attended);
                $ev['checked'] = (bool) ($m?->attendance_checked_at);            // presença apurada?
                $ev['summary'] = $m?->summary;                                    // resumo read.ai (se houver)
                $ev['reminder_sent'] = (bool) ($m && $m->reminder_sent_at && $m->phone); // lembrete WhatsApp enviado?
                $ev['conversation_slug'] = $m?->conversation?->slug;
                $ev['conversation_name'] = $m?->conversation?->name;
            }
            unset($ev);
        }

        return $out;
    }

    /** Opções comuns: cria Meet (conferenceDataVersion) e notifica convidados (sendUpdates). */
    private function writeParams(): array
    {
        return ['conferenceDataVersion' => 1, 'sendUpdates' => 'all'];
    }

    /**
     * Intervalos ocupados na agenda do usuário na janela pedida (só eventos com
     * hora marcada; dia-inteiro não bloqueia horário).
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    protected function busyIntervals(User $user, Carbon $from, Carbon $to): array
    {
        $events = $this->calendar($user)->events->listEvents($this->calendarId($user), [
            'timeMin' => $from->toRfc3339String(),
            'timeMax' => $to->toRfc3339String(),
            'singleEvents' => true,
            'orderBy' => 'startTime',
            'maxResults' => 250,
        ]);

        $busy = [];
        foreach ($events->getItems() as $e) {
            $s = $e->getStart()?->getDateTime();
            $en = $e->getEnd()?->getDateTime();
            if ($s && $en) {
                $busy[] = [Carbon::parse($s), Carbon::parse($en)];
            }
        }

        return $busy;
    }

    /**
     * Horários livres na agenda do usuário em horário comercial (seg–sex).
     * Caso de um anfitrião só — ver `freeSlotsForHosts` para o time.
     *
     * @return array<int, array{iso:string, label:string}>
     */
    public function freeSlots(User $user, int $durationMin = 45, int $daysAhead = 10, int $workStart = 9, int $workEnd = 18, int $max = 14, ?array $workDays = null): array
    {
        return $this->freeSlotsForHosts([$user], $durationMin, $daysAhead, $workStart, $workEnd, $max, $workDays);
    }

    /**
     * Horários em que PELO MENOS UM dos anfitriões está livre.
     *
     * A oferta é a UNIÃO das agendas, não a interseção: dois anfitriões atendem leads
     * diferentes em reuniões separadas, então 14h continua sendo horário oferecível
     * enquanto sobrar alguém livre nele. Interseção seria a regra de reunião conjunta —
     * daria o oposto do que se quer, que é dobrar a capacidade.
     *
     * Quem escolhe o anfitrião de cada horário é `firstFreeHost`, na hora de marcar.
     *
     * @param  iterable<User>  $hosts
     * @return array<int, array{iso:string, label:string}>
     */
    public function freeSlotsForHosts(iterable $hosts, int $durationMin = 45, int $daysAhead = 10, int $workStart = 9, int $workEnd = 18, int $max = 14, ?array $workDays = null): array
    {
        // Dias em que a empresa atende (ISO 1=seg…7=dom). Sem configuração, seg–sex —
        // o mesmo comportamento do antigo "pula fim de semana".
        $workDays = $workDays ?: [1, 2, 3, 4, 5];
        $tz = config('app.timezone', 'America/Sao_Paulo');
        $now = Carbon::now($tz);
        $from = $now->copy();
        $to = $now->copy()->addDays($daysAhead)->endOfDay();

        // Um mapa de ocupação POR anfitrião — juntar tudo num só faria a agenda de um
        // bloquear o horário do outro, que é exatamente o contrário da união.
        $busyPorHost = [];
        $ultimaFalha = null;
        $total = 0;
        foreach ($hosts as $h) {
            $total++;
            try {
                $busyPorHost[] = $this->busyIntervals($h, $from, $to);
            } catch (\Throwable $e) {
                // Agenda de UM anfitrião fora do ar (token revogado, API instável) não pode
                // derrubar o agendamento do time: ele só deixa de oferecer disponibilidade.
                // Tratá-lo como "livre" seria pior — marcaria por cima do que ele já tem.
                $ultimaFalha = $e;
            }
        }
        // Mas se NENHUM respondeu, o erro é real e sobe: quem chama distingue "agenda
        // cheia" (lista vazia) de "não consegui ler a agenda" (exceção), e engolir isso
        // faria a IA anunciar que não há horário quando na verdade não perguntou.
        if (! $busyPorHost) {
            if ($ultimaFalha && $total > 0) {
                throw $ultimaFalha;
            }

            return [];
        }

        $overlaps = function (Carbon $a1, Carbon $a2) use ($busyPorHost): bool {
            foreach ($busyPorHost as $busy) {
                $ocupado = false;
                foreach ($busy as [$b1, $b2]) {
                    if ($a1->lt($b2) && $a2->gt($b1)) {
                        $ocupado = true;
                        break;
                    }
                }
                if (! $ocupado) {
                    return false; // sobrou alguém livre — o horário vale
                }
            }

            return true;
        };

        $weekdays = ['', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado', 'domingo'];
        // Antecedência mínima: nunca ofertar para HOJE (padrão `antecedencia_dias` = 1).
        // Reunião marcada para daqui a duas horas pega o anfitrião de surpresa — sem tempo
        // de preparar a call nem de reorganizar o dia. A folga de 2h continua valendo como
        // piso quando a antecedência é zerada por configuração.
        $antecedencia = (int) config('services.agenda.antecedencia_dias', 1);
        $earliest = $now->copy()->addHours(2); // folga mínima a partir de agora
        if ($antecedencia > 0) {
            $earliest = $earliest->max($now->copy()->addDays($antecedencia)->startOfDay());
        }
        $perDay = 3; // poucos horários por dia para a lista COBRIR VÁRIOS DIAS, não esgotar tudo no 1º
        $slots = [];

        for ($d = 0; $d <= $daysAhead && count($slots) < $max; $d++) {
            $day = $now->copy()->addDays($d)->startOfDay();
            if (! in_array($day->isoWeekday(), $workDays, true)) {
                continue;
            }

            // Horários livres do dia inteiro...
            $dayFree = [];
            for ($min = $workStart * 60; $min + $durationMin <= $workEnd * 60; $min += 30) {
                $start = $day->copy()->addMinutes($min);
                $end = $start->copy()->addMinutes($durationMin);
                if ($start->lt($earliest) || $overlaps($start, $end)) {
                    continue;
                }
                $dayFree[] = $start;
            }
            if (! $dayFree) {
                continue;
            }

            // Horas despriorizadas (9h e meio-dia, por padrão): saem da SUGESTÃO enquanto o dia
            // tiver qualquer outra opção livre. Não é bloqueio — num dia em que só sobrou 9h ou
            // 12h eles voltam para a lista, e o lead que PEDIR esse horário continua sendo
            // marcado normalmente (quem valida a disponibilidade real na marcação é o isFree()).
            $despriorizadas = (array) config('services.agenda.horas_despriorizadas', []);
            if ($despriorizadas) {
                $preferidos = array_values(array_filter(
                    $dayFree,
                    fn (Carbon $s) => ! in_array((int) $s->format('G'), $despriorizadas, true),
                ));
                $dayFree = $preferidos ?: $dayFree;
            }

            // ...e pega só $perDay deles, espalhados (manhã/meio/tarde) em vez de seguidos.
            $n = count($dayFree);
            $take = min($perDay, $n);
            for ($i = 0; $i < $take && count($slots) < $max; $i++) {
                $start = $dayFree[$take > 1 ? (int) round($i * ($n - 1) / ($take - 1)) : 0];
                $slots[] = [
                    'iso' => $start->toIso8601String(),
                    'label' => $weekdays[$start->isoWeekday()].', '.$start->format('d/m').' às '.$start->format('H:i'),
                ];
            }
        }

        return $slots;
    }

    /**
     * Contatos do Google do usuário, mapeados pelos últimos 8 dígitos do telefone
     * (casa com/sem código do país e 9º dígito). [ '81925100' => 'Maia ✨' ].
     *
     * @return array<string, string>
     */
    public function contacts(User $user): array
    {
        $service = new \Google\Service\PeopleService($this->clientFor($user));
        $map = [];
        $pageToken = null;
        do {
            $resp = $service->people_connections->listPeopleConnections('people/me', [
                'personFields' => 'names,phoneNumbers',
                'pageSize' => 1000,
                'pageToken' => $pageToken,
            ]);
            foreach ($resp->getConnections() ?? [] as $person) {
                $names = $person->getNames();
                $name = $names ? trim((string) $names[0]->getDisplayName()) : '';
                if ($name === '') {
                    continue;
                }
                foreach ($person->getPhoneNumbers() ?? [] as $ph) {
                    $d = preg_replace('/\D/', '', (string) $ph->getValue());
                    if (strlen($d) >= 8) {
                        $map[substr($d, -8)] = $name;
                    }
                }
            }
            $pageToken = $resp->getNextPageToken();
        } while ($pageToken);

        return $map;
    }

    /**
     * Lista completa dos contatos do Google (p/ espelhar no sistema).
     *
     * @return array<int, array{resource_name:string, etag:?string, name:string, phone:?string, email:?string, avatar:?string}>
     */
    public function listContactsFull(User $user): array
    {
        $service = new \Google\Service\PeopleService($this->clientFor($user));
        $out = [];
        $pageToken = null;
        do {
            $resp = $service->people_connections->listPeopleConnections('people/me', [
                'personFields' => 'names,phoneNumbers,emailAddresses,photos',
                'pageSize' => 1000,
                'pageToken' => $pageToken,
            ]);
            foreach ($resp->getConnections() ?? [] as $p) {
                $names = $p->getNames();
                $phones = $p->getPhoneNumbers();
                $emails = $p->getEmailAddresses();
                $photos = $p->getPhotos();
                $out[] = [
                    'resource_name' => $p->getResourceName(),
                    'etag' => $p->getEtag(),
                    'name' => $names ? trim((string) $names[0]->getDisplayName()) : '',
                    'phone' => $phones ? $phones[0]->getValue() : null,
                    'email' => $emails ? $emails[0]->getValue() : null,
                    'avatar' => $photos ? ($photos[0]->getUrl() ?: null) : null,
                ];
            }
            $pageToken = $resp->getNextPageToken();
        } while ($pageToken);

        return $out;
    }

    /** Cria um contato no Google. @return array{resource_name:string, etag:?string} */
    public function createGoogleContact(User $user, string $name, ?string $phone, ?string $email): array
    {
        $service = new \Google\Service\PeopleService($this->clientFor($user));
        $person = $this->buildPerson($name, $phone, $email);
        $created = $service->people->createContact($person);

        return ['resource_name' => $created->getResourceName(), 'etag' => $created->getEtag()];
    }

    /** Atualiza um contato no Google (precisa do etag atual). @return string novo etag */
    public function updateGoogleContact(User $user, string $resourceName, ?string $etag, string $name, ?string $phone, ?string $email): ?string
    {
        $service = new \Google\Service\PeopleService($this->clientFor($user));
        // Pega o etag atual se não veio (o Google exige o etag mais recente).
        if (! $etag) {
            $etag = $service->people->get($resourceName, ['personFields' => 'metadata'])->getEtag();
        }
        $person = $this->buildPerson($name, $phone, $email);
        $person->setEtag($etag);
        $updated = $service->people->updateContact($resourceName, $person, [
            'updatePersonFields' => 'names,phoneNumbers,emailAddresses',
        ]);

        return $updated->getEtag();
    }

    public function deleteGoogleContact(User $user, string $resourceName): void
    {
        $service = new \Google\Service\PeopleService($this->clientFor($user));
        try {
            $service->people->deleteContact($resourceName);
        } catch (\Google\Service\Exception $e) {
            if (! in_array($e->getCode(), [404, 410], true)) {
                throw $e;
            }
        }
    }

    private function buildPerson(string $name, ?string $phone, ?string $email): \Google\Service\PeopleService\Person
    {
        $person = new \Google\Service\PeopleService\Person;
        $n = new \Google\Service\PeopleService\Name;
        $n->setGivenName($name);
        $person->setNames([$n]);
        if ($phone) {
            $ph = new \Google\Service\PeopleService\PhoneNumber;
            $ph->setValue($phone);
            $person->setPhoneNumbers([$ph]);
        }
        if ($email) {
            $em = new \Google\Service\PeopleService\EmailAddress;
            $em->setValue($email);
            $person->setEmailAddresses([$em]);
        }

        return $person;
    }

    /**
     * Verifica se um intervalo específico está livre na agenda do usuário.
     * $ignoreEventId: evento que NÃO conta como conflito (o que está sendo remarcado).
     */
    public function isFree(User $user, Carbon $start, Carbon $end, ?string $ignoreEventId = null): bool
    {
        $events = $this->calendar($user)->events->listEvents($this->calendarId($user), [
            'timeMin' => $start->copy()->subMinute()->toRfc3339String(),
            'timeMax' => $end->copy()->addMinute()->toRfc3339String(),
            'singleEvents' => true,
            'orderBy' => 'startTime',
            'maxResults' => 50,
        ]);

        foreach ($events->getItems() as $e) {
            if ($ignoreEventId && $e->getId() === $ignoreEventId) {
                continue;
            }
            $s = $e->getStart()?->getDateTime();
            $en = $e->getEnd()?->getDateTime();
            if ($s && $en && $start->lt(Carbon::parse($en)) && $end->gt(Carbon::parse($s))) {
                return false;
            }
        }

        return true;
    }

    /**
     * O primeiro anfitrião da lista que está REALMENTE livre no horário, ou null se
     * nenhum estiver. A ordem recebida é a preferência (ver MeetingScheduler::hosts).
     *
     * Confere na agenda de verdade, um a um, em vez de confiar no mapa de horários
     * oferecido: entre propor e o lead confirmar passam minutos ou dias, e nesse meio
     * alguém pode ter marcado outra coisa por fora do CRM.
     *
     * @param  iterable<User>  $hosts
     */
    public function firstFreeHost(iterable $hosts, Carbon $start, Carbon $end, ?string $ignoreEventId = null): ?User
    {
        foreach ($hosts as $h) {
            try {
                if ($this->isFree($h, $start, $end, $ignoreEventId)) {
                    return $h;
                }
            } catch (\Throwable $e) {
                continue; // agenda ilegível não conta como livre
            }
        }

        return null;
    }

    /** Cria um evento e devolve o normalizado (com o id do Google). */
    public function createEvent(User $user, array $data): array
    {
        $event = $this->fillEvent(new GoogleEvent, $data);
        $created = $this->calendar($user)->events->insert($this->calendarId($user), $event, $this->writeParams());

        // Meet novo nasce já com gravação automática ligada (best-effort).
        if (! empty($data['add_meet'])) {
            $this->enableAutoRecording($user, $created->getHangoutLink());
        }

        return $this->normalize($created);
    }

    /** Um evento específico, normalizado (null se não existe mais na agenda). */
    public function event(User $user, string $eventId): ?array
    {
        try {
            return $this->normalize($this->calendar($user)->events->get($this->calendarId($user), $eventId));
        } catch (\Google\Service\Exception $e) {
            if (in_array($e->getCode(), [404, 410], true)) {
                return null;
            }
            throw $e;
        }
    }

    public function updateEvent(User $user, string $eventId, array $data): array
    {
        $cal = $this->calendar($user);
        $event = $cal->events->get($this->calendarId($user), $eventId);
        $event = $this->fillEvent($event, $data);
        $updated = $cal->events->update($this->calendarId($user), $eventId, $event, $this->writeParams());

        // Se a edição criou o Meet agora, liga a gravação automática nele também.
        if (! empty($data['add_meet'])) {
            $this->enableAutoRecording($user, $updated->getHangoutLink());
        }

        return $this->normalize($updated);
    }

    public function deleteEvent(User $user, string $eventId): void
    {
        try {
            $this->calendar($user)->events->delete($this->calendarId($user), $eventId);
        } catch (\Google\Service\Exception $e) {
            // 404/410: evento já não existe no Google — ok, segue.
            if (! in_array($e->getCode(), [404, 410], true)) {
                throw $e;
            }
        }
    }

    /** Aplica os campos do payload num Event do Google. */
    private function fillEvent(GoogleEvent $event, array $data): GoogleEvent
    {
        if (array_key_exists('title', $data)) {
            $event->setSummary($data['title']);
        }
        if (array_key_exists('description', $data)) {
            $event->setDescription($data['description']);
        }
        if (array_key_exists('location', $data)) {
            $event->setLocation($data['location']);
        }
        if (! empty($data['starts_at'])) {
            $event->setStart($this->dateTime($data['starts_at']));
        }
        if (! empty($data['ends_at'])) {
            $event->setEnd($this->dateTime($data['ends_at']));
        }

        // Convidados: lista de e-mails substitui os atuais (o front manda a lista completa).
        if (array_key_exists('attendees', $data)) {
            $attendees = [];
            foreach ((array) ($data['attendees'] ?? []) as $email) {
                $email = trim((string) $email);
                if ($email === '') {
                    continue;
                }
                $a = new EventAttendee;
                $a->setEmail($email);
                $attendees[] = $a;
            }
            $event->setAttendees($attendees);
        }

        // Link do Google Meet: só cria se pedido e ainda não existir um.
        if (! empty($data['add_meet']) && ! $event->getHangoutLink() && ! $event->getConferenceData()) {
            $solutionKey = new ConferenceSolutionKey;
            $solutionKey->setType('hangoutsMeet');
            $createRequest = new CreateConferenceRequest;
            $createRequest->setRequestId('meet-'.Str::uuid()->toString());
            $createRequest->setConferenceSolutionKey($solutionKey);
            $conf = new ConferenceData;
            $conf->setCreateRequest($createRequest);
            $event->setConferenceData($conf);
        }

        // Vincula o evento a um Negócio/Tarefa do CRM (round-trip via extendedProperties privadas).
        if (array_key_exists('deal_id', $data) || array_key_exists('task_id', $data)) {
            $private = [];
            if (! empty($data['deal_id'])) {
                $private['crm_deal_id'] = (string) $data['deal_id'];
            }
            if (! empty($data['task_id'])) {
                $private['crm_task_id'] = (string) $data['task_id'];
            }
            $ext = new \Google\Service\Calendar\EventExtendedProperties;
            $ext->setPrivate($private);
            $event->setExtendedProperties($ext);
        }

        return $event;
    }

    private function dateTime(string $iso): EventDateTime
    {
        $dt = new EventDateTime;
        $dt->setDateTime(Carbon::parse($iso)->toRfc3339String());
        $dt->setTimeZone(config('app.timezone', 'America/Sao_Paulo'));

        return $dt;
    }

    /** Formato enxuto consumido pelo front. */
    private function normalize(GoogleEvent $e): array
    {
        $start = $e->getStart();
        $end = $e->getEnd();
        $ext = $e->getExtendedProperties();
        $private = $ext ? ($ext->getPrivate() ?? []) : [];

        $attendees = [];
        foreach ($e->getAttendees() ?? [] as $a) {
            // Ignora salas/recursos; mantém pessoas.
            if ($a->getResource()) {
                continue;
            }
            $attendees[] = [
                'email' => $a->getEmail(),
                'name' => $a->getDisplayName(),
                'response' => $a->getResponseStatus(), // needsAction|accepted|declined|tentative
                'organizer' => (bool) $a->getOrganizer(),
            ];
        }

        return [
            'id' => $e->getId(),
            'title' => $e->getSummary() ?? '(sem título)',
            'description' => $e->getDescription(),
            'location' => $e->getLocation(),
            'starts_at' => $start ? ($start->getDateTime() ?? $start->getDate()) : null,
            'ends_at' => $end ? ($end->getDateTime() ?? $end->getDate()) : null,
            'all_day' => $start ? empty($start->getDateTime()) : false,
            'html_link' => $e->getHtmlLink(),
            'hangout_link' => $e->getHangoutLink(),
            'attendees' => $attendees,
            'deal_id' => isset($private['crm_deal_id']) ? (int) $private['crm_deal_id'] : null,
            'task_id' => isset($private['crm_task_id']) ? (int) $private['crm_task_id'] : null,
        ];
    }
}
