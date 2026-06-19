<?php

namespace App\Services;

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
            // Gmail (somente leitura): ler os relatórios de reunião do read.ai por e-mail.
            \Google\Service\Gmail::GMAIL_READONLY,
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

    /** Serviço da Meet REST API autenticado para o usuário. */
    public function meet(User $user): \Google\Service\Meet
    {
        return new \Google\Service\Meet($this->clientFor($user));
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
     * Casa o conference record cujo início está mais próximo de $start e devolve os participantes
     * com o tempo (min) em sala. Separa o bot de anotação dos humanos.
     *
     * @return array{found:bool, participants:array<int,array{name:string,minutes:int,bot:bool}>}
     */
    public function conferenceAttendance(User $user, string $meetingCode, Carbon $start): array
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

        // Várias reuniões podem ter usado o mesmo link; pega a do horário combinado.
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
        // Mais de 6h de diferença do horário marcado: provavelmente não é esta reunião.
        if (! $best || $bestDiff > 6 * 3600) {
            return ['found' => false, 'participants' => []];
        }

        $parts = $meet->conferenceRecords_participants
            ->listConferenceRecordsParticipants($best->getName(), ['pageSize' => 100])
            ->getParticipants() ?? [];

        $out = [];
        foreach ($parts as $p) {
            $name = $p->getSignedinUser()?->getDisplayName()
                ?: $p->getAnonymousUser()?->getDisplayName()
                ?: ($p->getPhoneUser() ? 'Telefone' : 'Desconhecido');
            $in = $p->getEarliestStartTime() ? Carbon::parse($p->getEarliestStartTime()) : null;
            $end = $p->getLatestEndTime() ? Carbon::parse($p->getLatestEndTime()) : Carbon::now();
            $minutes = ($in && $end) ? (int) round($in->diffInSeconds($end) / 60) : 0;
            $out[] = ['name' => $name, 'minutes' => max(0, $minutes), 'bot' => $this->isNotetakerBot($name)];
        }

        return ['found' => true, 'participants' => $out];
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
                // No-show: presença JÁ apurada e o cliente NÃO compareceu → vermelho na agenda.
                $ev['no_show'] = (bool) ($m && $m->attendance_checked_at !== null && ! $m->attended);
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
     * Horários livres na agenda do usuário em horário comercial (seg–sex),
     * considerando os eventos com hora marcada como ocupados.
     *
     * @return array<int, array{iso:string, label:string}>
     */
    public function freeSlots(User $user, int $durationMin = 45, int $daysAhead = 10, int $workStart = 9, int $workEnd = 18, int $max = 14): array
    {
        $tz = config('app.timezone', 'America/Sao_Paulo');
        $now = Carbon::now($tz);
        $from = $now->copy();
        $to = $now->copy()->addDays($daysAhead)->endOfDay();

        $events = $this->calendar($user)->events->listEvents($this->calendarId($user), [
            'timeMin' => $from->toRfc3339String(),
            'timeMax' => $to->toRfc3339String(),
            'singleEvents' => true,
            'orderBy' => 'startTime',
            'maxResults' => 250,
        ]);

        // Intervalos ocupados (só eventos com hora; ignora dia-inteiro).
        $busy = [];
        foreach ($events->getItems() as $e) {
            $s = $e->getStart()?->getDateTime();
            $en = $e->getEnd()?->getDateTime();
            if ($s && $en) {
                $busy[] = [Carbon::parse($s), Carbon::parse($en)];
            }
        }

        $overlaps = function (Carbon $a1, Carbon $a2) use ($busy): bool {
            foreach ($busy as [$b1, $b2]) {
                if ($a1->lt($b2) && $a2->gt($b1)) {
                    return true;
                }
            }

            return false;
        };

        $weekdays = ['', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado', 'domingo'];
        $earliest = $now->copy()->addHours(2); // folga mínima a partir de agora
        $perDay = 3; // poucos horários por dia para a lista COBRIR VÁRIOS DIAS, não esgotar tudo no 1º
        $slots = [];

        for ($d = 0; $d <= $daysAhead && count($slots) < $max; $d++) {
            $day = $now->copy()->addDays($d)->startOfDay();
            if ($day->isWeekend()) {
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

    /** Verifica se um intervalo específico está livre na agenda do usuário. */
    public function isFree(User $user, Carbon $start, Carbon $end): bool
    {
        $events = $this->calendar($user)->events->listEvents($this->calendarId($user), [
            'timeMin' => $start->copy()->subMinute()->toRfc3339String(),
            'timeMax' => $end->copy()->addMinute()->toRfc3339String(),
            'singleEvents' => true,
            'orderBy' => 'startTime',
            'maxResults' => 50,
        ]);

        foreach ($events->getItems() as $e) {
            $s = $e->getStart()?->getDateTime();
            $en = $e->getEnd()?->getDateTime();
            if ($s && $en && $start->lt(Carbon::parse($en)) && $end->gt(Carbon::parse($s))) {
                return false;
            }
        }

        return true;
    }

    /** Cria um evento e devolve o normalizado (com o id do Google). */
    public function createEvent(User $user, array $data): array
    {
        $event = $this->fillEvent(new GoogleEvent, $data);
        $created = $this->calendar($user)->events->insert($this->calendarId($user), $event, $this->writeParams());

        return $this->normalize($created);
    }

    public function updateEvent(User $user, string $eventId, array $data): array
    {
        $cal = $this->calendar($user);
        $event = $cal->events->get($this->calendarId($user), $eventId);
        $event = $this->fillEvent($event, $data);
        $updated = $cal->events->update($this->calendarId($user), $eventId, $event, $this->writeParams());

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
