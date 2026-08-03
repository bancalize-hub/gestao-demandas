<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab do chat = um TIME (SDR, Closer, CS): agrupa etapas do funil e carrega o
 * objetivo que a IA persegue com os leads que estão nessas etapas.
 */
class ChatTab extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = ['stages' => 'array'];

    /**
     * Time responsável por uma etapa do funil. A primeira tab que lista a etapa
     * ganha — etapa em duas tabs é configuração ambígua, e a ordem da tela é a
     * resposta menos surpreendente.
     */
    public static function forStage(?string $stage): ?self
    {
        if (! $stage) {
            return null;
        }

        return static::orderBy('position')->orderBy('id')->get()
            ->first(fn (self $t) => in_array($stage, (array) $t->stages, true));
    }
}
