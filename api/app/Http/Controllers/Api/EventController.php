<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * A agenda do CRM é UMA SÓ: a da empresa. Cada usuário com Google conectado é uma camada
 * que se liga/desliga na tela, como as "Minhas agendas" do Google Agenda.
 *
 * Antes cada usuário via apenas a própria agenda Google. Como quem marca as reuniões é
 * sempre a primeira conta Google da empresa (a IA e o botão "Agendar"), qualquer outro
 * usuário abria a Agenda vazia e as reuniões marcadas simplesmente não apareciam.
 */
class EventController extends Controller
{
    public function __construct(private GoogleCalendarService $google) {}

    /** As agendas da empresa: todo usuário com Google conectado, em ordem estável. */
    private function calendarUsers(Request $request): Collection
    {
        return User::query()
            ->where('company_id', $request->user()->company_id)
            ->whereNotNull('google_refresh_token')
            ->orderBy('id')
            ->get();
    }

    /**
     * Em qual agenda escrever: a escolhida no formulário, a de quem está logado (se tiver
     * Google) ou, por último, a primeira da empresa — a mesma que a IA usa para marcar.
     */
    private function ownerFor(Request $request, ?int $ownerId): User
    {
        $donos = $this->calendarUsers($request);
        abort_if($donos->isEmpty(), 409, 'Nenhuma conta Google conectada nesta empresa.');

        if ($ownerId && ($escolhido = $donos->firstWhere('id', $ownerId))) {
            return $escolhido;
        }

        return $donos->firstWhere('id', $request->user()->id) ?? $donos->first();
    }

    /** Carimba o evento com a agenda de onde ele veio (cor/nome do dono na tela). */
    private function withOwner(array $event, User $dono): array
    {
        return $event + [
            'owner_id' => $dono->id,
            'owner_name' => $dono->name,
            'owner_email' => $dono->google_email,
            'owner_color' => GoogleCalendarService::corDaAgenda($dono->id),
        ];
    }

    /**
     * Eventos de TODAS as agendas da empresa num intervalo (?from=ISO&to=ISO).
     * `?users=1,4` restringe a algumas agendas. Padrão: semana atual, todas as agendas.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'users' => 'nullable|string',
        ]);

        $donos = $this->calendarUsers($request);
        abort_if($donos->isEmpty(), 409, 'Nenhuma conta Google conectada nesta empresa.');

        $filtro = array_filter(array_map('intval', explode(',', (string) ($data['users'] ?? ''))));
        if ($filtro) {
            $donos = $donos->whereIn('id', $filtro);
        }

        $from = isset($data['from']) ? Carbon::parse($data['from']) : Carbon::now()->startOfWeek();
        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::now()->endOfWeek();

        $eventos = [];
        $vistos = [];
        foreach ($donos as $dono) {
            try {
                $lista = $this->google->listEvents($dono, $from, $to);
            } catch (\Throwable $e) {
                // Um token quebrado não pode derrubar a agenda inteira da empresa: as
                // demais agendas continuam aparecendo e a tela avisa qual falhou.
                Log::warning('Agenda da empresa: falha ao ler a agenda de um usuário', [
                    'user_id' => $dono->id,
                    'erro' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($lista as $evento) {
                // O mesmo evento aparece na agenda de todo mundo que foi convidado —
                // mostra uma vez só, na agenda de quem apareceu primeiro (o organizador).
                if (isset($vistos[$evento['id']])) {
                    continue;
                }
                $vistos[$evento['id']] = true;
                $eventos[] = $this->withOwner($evento, $dono);
            }
        }

        usort($eventos, fn ($a, $b) => strtotime((string) $a['starts_at']) <=> strtotime((string) $b['starts_at']));

        return response()->json(['events' => array_values($eventos)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after_or_equal:starts_at',
            'deal_id' => 'nullable|integer|exists:deals,id',
            'task_id' => 'nullable|integer|exists:tasks,id',
            'attendees' => 'sometimes|array',
            'attendees.*' => 'email',
            'add_meet' => 'sometimes|boolean',
            'conversation_slug' => 'nullable|string',
            'owner_id' => 'nullable|integer',
        ]);

        $dono = $this->ownerFor($request, $data['owner_id'] ?? null);

        $event = $this->google->createEvent($dono, $data);
        $this->linkMeeting($dono, $event, $request->input('conversation_slug'));

        return response()->json($this->withOwner($event, $dono), 201);
    }

    public function update(Request $request, string $event)
    {
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'starts_at' => 'sometimes|date',
            'ends_at' => 'sometimes|date|after_or_equal:starts_at',
            'deal_id' => 'nullable|integer|exists:deals,id',
            'task_id' => 'nullable|integer|exists:tasks,id',
            'attendees' => 'sometimes|array',
            'attendees.*' => 'email',
            'add_meet' => 'sometimes|boolean',
            'conversation_slug' => 'nullable|string',
            'owner_id' => 'nullable|integer',
        ]);

        // O evento mora na agenda do dono — editar pela agenda de outro usuário daria 404.
        $dono = $this->ownerFor($request, $data['owner_id'] ?? null);

        $updated = $this->google->updateEvent($dono, $event, $data);
        if ($request->has('conversation_slug')) {
            $this->linkMeeting($dono, $updated, $request->input('conversation_slug'));
        }

        return response()->json($this->withOwner($updated, $dono));
    }

    public function destroy(Request $request, string $event)
    {
        $dono = $this->ownerFor($request, $request->integer('owner_id') ?: null);

        $this->google->deleteEvent($dono, $event);

        return response()->json(['message' => 'ok']);
    }

    /**
     * Marca à mão se a reunião aconteceu.
     *
     * A apuração automática lê o Google Meet, e o Meet só sabe de quem ENTROU na sala: reunião
     * feita por telefone, presencial ou em outro link fica sem medição para sempre — e era isso
     * que deixava reunião realizada sem marca nenhuma na agenda. Aqui o atendente resolve, e a
     * marcação vale o mesmo que a automática (move o lead para "Reunião Realizada" e conta a
     * conversão para a Meta).
     */
    public function attendance(Request $request, string $event)
    {
        $data = $request->validate([
            'attended' => 'required|boolean',
            'owner_id' => 'nullable|integer',
            'conversation_slug' => 'nullable|string',
        ]);
        $attended = (bool) $data['attended'];

        $meeting = \App\Models\Meeting::where('google_event_id', $event)->first();

        // Compromisso criado direto no Google Agenda SEM link do Meet nunca entra em `meetings`
        // (o calendar-sync só importa reunião com Meet). Sem esta linha o botão daria 404
        // justamente no caso que mais precisa dele: a reunião que o Meet não tem como medir.
        if (! $meeting) {
            $dono = $this->ownerFor($request, $data['owner_id'] ?? null);
            $evento = $this->google->event($dono, $event);
            if (! $evento || empty($evento['starts_at'])) {
                return response()->json(['message' => 'Este evento não existe mais na agenda.'], 404);
            }

            $conv = ! empty($data['conversation_slug'])
                ? \App\Models\Conversation::where('slug', $data['conversation_slug'])->first()
                : null;

            $meeting = \App\Models\Meeting::create([
                'conversation_id' => $conv?->id,
                'user_id' => $dono->id,
                'phone' => $conv?->phone,
                'title' => $evento['title'] ?? 'Reunião',
                'starts_at' => Carbon::parse($evento['starts_at']),
                'ends_at' => ! empty($evento['ends_at']) ? Carbon::parse($evento['ends_at']) : null,
                'meet_link' => $evento['hangout_link'] ?? null,
                'google_event_id' => $event,
                'reminder_sent_at' => now(), // passada: não faz sentido lembrar
            ]);
        }

        $meeting->attended = $attended;
        // Medição manual: minutos ficam nulos (não medimos nada), mas a apuração está encerrada.
        // O 0 explícito no "não aconteceu" é o que faz o card ficar vermelho em vez de neutro.
        $meeting->attended_minutes = $attended ? null : 0;
        $meeting->attendance_checked_at = now();
        $meeting->save();

        $quando = $meeting->starts_at->setTimezone(config('app.timezone'))->format('d/m H:i');
        $quem = $request->user();

        if ($meeting->conversation) {
            if ($attended) {
                \App\Services\StageMover::move(
                    $meeting->conversation,
                    (string) config('services.crm.stage_meeting_done', 'reuniao-realizada'),
                    $quem?->id,
                    "Reunião realizada em {$quando}",
                );
                \App\Models\LeadActivity::log($meeting->conversation->id, 'reuniao', "✅ Reunião realizada ({$quando}) — marcada por {$quem?->name}", null, $quem?->id);
                \App\Support\MetaConversions::enviarUmaVez($meeting->conversation, \App\Support\MetaConversions::REUNIAO_REALIZADA);
            } else {
                \App\Models\LeadActivity::log($meeting->conversation->id, 'reuniao', "⚠️ Reunião não aconteceu ({$quando}) — marcada por {$quem?->name}", null, $quem?->id);
            }
        }

        return response()->json(['attended' => $attended, 'no_show' => ! $attended]);
    }

    /**
     * Liga (ou desliga) a reunião a um lead criando/atualizando a linha em `meetings`,
     * para o card da agenda abrir a ficha e o pós-reunião (presença/resumo) processar.
     */
    private function linkMeeting(User $user, array $event, ?string $slug): void
    {
        if (empty($event['id'])) {
            return;
        }
        $conv = $slug ? \App\Models\Conversation::where('slug', $slug)->first() : null;
        if (! $conv) {
            // Desvincula: se havia Meeting criada por este fluxo, solta o lead.
            \App\Models\Meeting::where('google_event_id', $event['id'])->update(['conversation_id' => null]);

            return;
        }

        \App\Models\Meeting::updateOrCreate(
            ['google_event_id' => $event['id']],
            [
                'conversation_id' => $conv->id,
                'user_id' => $user->id,
                'phone' => $conv->phone,
                'title' => $event['title'] ?? 'Reunião',
                'starts_at' => ! empty($event['starts_at']) ? \Carbon\Carbon::parse($event['starts_at']) : null,
                'ends_at' => ! empty($event['ends_at']) ? \Carbon\Carbon::parse($event['ends_at']) : null,
                'meet_link' => $event['hangout_link'] ?? null,
                'reminder_lead_minutes' => (int) config('services.meeting_reminder.lead_minutes', 60),
            ]
        );
    }

    /** Gravações das reuniões de um lead — alimenta o bloco de vídeos da ficha. */
    public function recordings(\App\Models\Conversation $conversation)
    {
        return \App\Models\Meeting::where('conversation_id', $conversation->id)
            ->whereNotNull('recording_file_id')
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn ($m) => [
                'meeting_id' => $m->id,
                'title' => $m->title,
                'starts_at' => $m->starts_at?->toIso8601String(),
                'url' => "/api/meetings/{$m->id}/recording",
            ]);
    }

    /**
     * Serve o VÍDEO da gravação (streaming do Drive com o token do anfitrião, repassando
     * Range). O arquivo nunca fica público nem ocupa disco: o CRM é só o cano — e a
     * autorização é a do próprio CRM (login + empresa), não a do Drive.
     */
    public function recording(\App\Models\Meeting $meeting)
    {
        abort_unless($meeting->recording_file_id, 404);
        set_time_limit(0); // gravação de 1h passa fácil do max_execution_time padrão

        $user = $meeting->user_id ? User::find($meeting->user_id) : null;
        if (! $user || ! $user->hasGoogle()) {
            // O comentário antigo dizia "mesma empresa (tenant scope)" e estava ERRADO:
            // `User` não tem escopo de tenant. Sem o filtro explícito, a gravação de uma
            // empresa seria buscada no Drive de outra.
            $user = User::where('company_id', $meeting->company_id)
                ->whereNotNull('google_access_token')
                ->first();
        }
        abort_unless($user && $user->hasGoogle(), 404);

        $up = $this->google->driveDownload($user, $meeting->recording_file_id, request()->header('Range'));
        abort_if($up->getStatusCode() >= 400, 502, 'Drive recusou a gravação');

        $headers = array_filter([
            'Content-Type' => $up->getHeaderLine('Content-Type') ?: 'video/mp4',
            'Content-Length' => $up->getHeaderLine('Content-Length') ?: null,
            'Content-Range' => $up->getHeaderLine('Content-Range') ?: null,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
        ]);

        return response()->stream(function () use ($up) {
            $body = $up->getBody();
            while (! $body->eof()) {
                echo $body->read(262144);
                if (connection_aborted()) {
                    break; // usuário fechou o player — para de puxar do Drive
                }
                flush();
            }
        }, $up->getStatusCode(), $headers);
    }
}
