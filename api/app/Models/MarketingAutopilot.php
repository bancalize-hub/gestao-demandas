<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuração do piloto automático de anúncios (uma por empresa).
 *
 * É o contrato do que o robô pode fazer com dinheiro: teto diário da conta, verba por
 * criativo, quantos novos por dia e quando matar. Tudo nasce desligado — ligar é decisão
 * de gente, pelo painel.
 */
class MarketingAutopilot extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'use_stock' => 'boolean',
        'daily_cap' => 'float',
        'max_cap' => 'float',
        'current_cap' => 'float',
        'target_cpq' => 'float',
        'manage_whole_account' => 'boolean',
        'alert_wa_account_id' => 'integer',
        'budget_per_ad' => 'float',
        'max_spend_no_qualified' => 'float',
        'max_new_per_day' => 'integer',
        'lifetime_days' => 'integer',
        'min_triados' => 'integer',
        'kill_rate' => 'integer',
        'image_api_key' => 'encrypted',
        'targeting' => 'array',
        'run_requested_at' => 'datetime',
        'last_run_at' => 'datetime',
        'last_report_at' => 'datetime',
        'last_review_at' => 'datetime',
        'next_review_at' => 'datetime',
    ];

    /** A chave da API de imagem nunca volta para a tela — só se está preenchida. */
    protected $hidden = ['image_api_key'];

    protected $appends = ['tem_chave_imagem'];

    public const PROVEDORES = ['openai', 'google', 'ideogram'];

    /**
     * O público que qualifica nesta conta (medido 10–17/08/2026 pelo Purchase da CAPI):
     * homem, 18–54, só Instagram (feed, stories, reels), sem Advantage+ de público.
     */
    public const PUBLICO_PADRAO = [
        'geo_locations' => ['countries' => ['BR'], 'location_types' => ['home', 'recent']],
        'age_min' => 18,
        'age_max' => 54,
        'genders' => [1],
        'publisher_platforms' => ['instagram'],
        'instagram_positions' => ['stream', 'story', 'reels'],
        'targeting_automation' => ['advantage_audience' => 0],
    ];

    public function getTemChaveImagemAttribute(): bool
    {
        return filled($this->image_api_key);
    }

    public static function atual(): self
    {
        $cfg = static::firstOrCreate([], []);

        // Recém-criado, o model não conhece os padrões do banco (teto, vida útil, corte):
        // sem recarregar, a primeira rodada leria tudo null e não pausaria nada.
        return $cfg->wasRecentlyCreated ? $cfg->fresh() : $cfg;
    }

    /**
     * O teto que vale agora: o que o gestor escolheu, nunca acima do máximo. Pode ficar
     * ABAIXO do normal — quando nada qualifica, gastar o teto inteiro é só queimar saldo.
     * O clamp mora aqui (e não só em quem grava) porque o dono pode baixar o máximo pelo
     * painel depois que o gestor subiu — e o teto em vigor tem que obedecer na hora.
     */
    public function tetoVigente(): float
    {
        $max = max($this->daily_cap, $this->max_cap);

        return min($max, max(0.0, (float) ($this->current_cap ?? $this->daily_cap)));
    }

    public function publico(): array
    {
        return $this->targeting ?: self::PUBLICO_PADRAO;
    }
}
