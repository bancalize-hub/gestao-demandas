<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma coisa aprendida sobre os anúncios desta empresa.
 *
 * Não confundir com {@see MemoryChunk}: aquilo é o que a IA sabe sobre o PRODUTO para
 * responder ao lead; isto é o que a casa sabe sobre a MÍDIA — qual criativo traz gente
 * boa, qual público desperdiça, o que já foi testado e não funcionou.
 */
class MarketingMemory extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = ['fixado' => 'boolean'];

    public const CATEGORIAS = ['criativo', 'publico', 'orcamento', 'metrica', 'aprendizado'];

    public const CONFIANCAS = ['medido', 'hipotese'];

    /**
     * A memória inteira em texto, para injetar no prompt do agente de marketing.
     *
     * Fixadas primeiro e mais novas antes das velhas: quando a memória crescer e o
     * prompt precisar de corte, o que sai é o antigo e não-fixado.
     */
    public static function contexto(int $limite = 60): string
    {
        $linhas = static::orderByDesc('fixado')
            ->orderByDesc('updated_at')
            ->limit($limite)
            ->get()
            ->map(function (self $m) {
                $quando = $m->periodo ? " ({$m->periodo})" : '';
                $duvida = $m->confianca === 'hipotese' ? ' [HIPÓTESE, não confirmada]' : '';

                return "- [{$m->categoria}] {$m->titulo}{$quando}{$duvida}: {$m->conteudo}";
            });

        return $linhas->isEmpty() ? '' : $linhas->implode("\n");
    }
}
