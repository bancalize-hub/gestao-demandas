<?php

namespace App\Models;

use App\Enums\Frequencia;
use App\Enums\Prioridade;
use App\Enums\StatusTicket;
use App\Enums\TipoTicket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoTicket::class,
            'prioridade' => Prioridade::class,
            'status' => StatusTicket::class,
            'frequencia' => Frequencia::class,
            'data_solicitacao' => 'datetime',
            'data_hora_ocorrencia' => 'datetime',
            'prazo_desejado' => 'date',
            'ordem' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Ticket $ticket) {
            $ticket->updateQuietly([
                'codigo' => sprintf('DEM-%04d', $ticket->id),
            ]);
        });
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(Anexo::class);
    }
}
