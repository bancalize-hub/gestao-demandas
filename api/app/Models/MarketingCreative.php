<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class MarketingCreative extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = ['size' => 'integer', 'number' => 'integer'];

    /**
     * Próximo nome da sequência. Usa o maior `number` existente em vez de count()
     * para que apagar o Criativo 2 não faça o próximo colidir com o Criativo 3.
     */
    public static function proximoNumero(): int
    {
        return (int) static::max('number') + 1;
    }

    /** Acha por "Criativo 3", "criativo 3" ou só "3" — é assim que a IA se refere. */
    public static function porReferencia(string $ref): ?self
    {
        $ref = trim($ref);
        if (preg_match('/(\d+)/', $ref, $m)) {
            $porNumero = static::where('number', (int) $m[1])->first();
            if ($porNumero) {
                return $porNumero;
            }
        }

        return static::whereRaw('LOWER(name) = ?', [mb_strtolower($ref)])->first();
    }
}
