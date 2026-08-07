<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Meeting extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reminder_lead_minutes' => 'integer',
        // Coluna json com os participantes do Meet (nome/minutos/bot), escrita só pelo
        // MeetingAttendanceTick. Sem o cast ela voltava como string e Meeting::clientMinutes
        // recebia texto em vez de lista.
        'attendees' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Reuniões que ainda valem: não canceladas. É a base da regra de ouro do
     * agendamento (no máximo UM agendamento ativo por contato).
     */
    public function scopeNotCancelled($query)
    {
        return $query->whereNull('cancelled_at');
    }

    /** Ativa = não cancelada e ainda por acontecer. */
    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at')->where('starts_at', '>', now());
    }

    /** O agendamento ativo de um contato (o mais próximo), ou null. */
    public static function activeFor(int $conversationId): ?self
    {
        return static::where('conversation_id', $conversationId)
            ->active()
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Quantos minutos o CLIENTE ficou na sala, a partir dos participantes do Meet.
     *
     * A regra antiga era "o 2º humano que mais ficou é o cliente", partindo de que na sala
     * há o host e o convidado. Só que **quando o Paulo e a Maysa entram e o lead não**, o 2º
     * humano é a própria equipe — e a reunião era gravada como comparecimento. Aconteceu 4
     * vezes entre 28/07 e 07/08/2026, inclusive mandando o evento de "Reunião realizada"
     * para a Meta e movendo a etapa do funil de quem nunca apareceu.
     *
     * Agora é explícito: descarta bot e descarta quem está na lista do time
     * (`services.crm.team_display_names`), e o cliente é o maior tempo do que sobra. Zero
     * significa que ninguém do lado do cliente entrou.
     *
     * @param  array<int, array{name?: string, minutes?: int, bot?: bool}>  $participants
     */
    public static function clientMinutes(?array $participants): int
    {
        $time = array_map(
            fn ($n) => mb_strtolower(trim((string) $n)),
            (array) config('services.crm.team_display_names', [])
        );

        $minutos = collect($participants ?? [])
            ->reject(fn ($p) => (bool) ($p['bot'] ?? false))
            ->reject(fn ($p) => in_array(mb_strtolower(trim((string) ($p['name'] ?? ''))), $time, true))
            ->map(fn ($p) => (int) ($p['minutes'] ?? 0));

        return (int) ($minutos->max() ?? 0);
    }
}
