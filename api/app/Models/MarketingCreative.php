<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class MarketingCreative extends Model
{
    use BelongsToCompany;

    /** Ainda sem veredito: subiu, talvez esteja rodando, ninguém decidiu. */
    public const TESTANDO = 'testando';

    /** Deu resultado — é o que se deve repetir. */
    public const VALIDADO = 'validado';

    /** Deu ruim. Não volta ao ar: a criação de anúncio recusa. */
    public const REPROVADO = 'reprovado';

    public const STATUS = [self::TESTANDO, self::VALIDADO, self::REPROVADO];

    protected $guarded = [];

    protected $casts = ['size' => 'integer', 'number' => 'integer', 'status_at' => 'datetime'];

    public function reprovado(): bool
    {
        return $this->status === self::REPROVADO;
    }

    /** Como o status é dito para a IA — o rótulo carrega a instrução junto. */
    public function statusParaIa(): string
    {
        return match ($this->status) {
            self::VALIDADO => 'VALIDADO — deu resultado, prefira este',
            self::REPROVADO => 'REPROVADO — deu ruim, NÃO usar em anúncio novo',
            default => 'em teste — sem veredito ainda',
        };
    }

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
