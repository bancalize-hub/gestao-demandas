<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um número de WhatsApp conectado (uma instância da Evolution).
 * O número principal (role=primary) atende anúncios com IA; os de prospecção
 * (role=outreach) fazem disparo/prospecção e têm respostas atendidas por humano.
 */
class WaAccount extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'daily_cap' => 'integer',
        'warmup_day' => 'integer',
        'sent_today' => 'integer',
        'sent_date' => 'date',
        'last_sent_at' => 'datetime',
        // Monitor de saúde (wa:health-tick). Sem cast, a segunda passada do tick lê
        // o valor gravado pela primeira como string e quebra em `->diffForHumans()`.
        'state_changed_at' => 'datetime',
        'health_checked_at' => 'datetime',
        'alerted_at' => 'datetime',
        'coexistence' => 'boolean',
        'avatars_only' => 'boolean',
        // Token e segredo do app da Meta são credenciais de longa duração: ficam
        // criptografados no banco (dump/backup vazado não entrega o WhatsApp da empresa).
        'access_token' => 'encrypted',
        'app_secret' => 'encrypted',
    ];

    /** Segredos nunca saem numa resposta de API. */
    protected $hidden = ['access_token', 'app_secret', 'verify_token'];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Número mantido conectado só como fonte de foto de perfil: não recebe e não envia.
     *
     * Só faz sentido na Evolution — é ela que consulta foto de qualquer telefone, e é ela
     * que a gente quer fora do caminho de mensagem. Marcar um número da Cloud API assim
     * não teria efeito nenhum de foto e ainda o tiraria do ar, então a flag é ignorada lá.
     */
    public function somenteFotos(): bool
    {
        return (bool) $this->avatars_only && ! $this->isCloud();
    }

    public function isPrimary(): bool
    {
        return $this->role === 'primary';
    }

    /** Fala pela API oficial da Meta (Cloud API) em vez da Evolution? */
    public function isCloud(): bool
    {
        return $this->provider === 'cloud';
    }

    /**
     * Resolve a conta pelo phone_number_id que vem no webhook da Meta.
     * Sem escopo de empresa: o webhook chega sem tenant e o id é único global.
     */
    public static function byPhoneNumberId(?string $phoneNumberId): ?self
    {
        if (! $phoneNumberId) {
            return null;
        }

        return static::withoutGlobalScopes()
            ->where('provider', 'cloud')
            ->where('phone_number_id', $phoneNumberId)
            ->first();
    }

    /**
     * A conta principal (atende anúncios) DA EMPRESA ATUAL.
     * Sem cache estático: em multi-tenant o processo alterna entre empresas
     * (agendador/webhook), então cachear vazaria a principal de uma no contexto da outra.
     */
    public static function primary(): ?self
    {
        return static::where('role', 'primary')->first();
    }

    /** Resolve a conta pelo nome da instância recebido no webhook da Evolution. */
    public static function byInstance(?string $instance): ?self
    {
        if (! $instance) {
            return null;
        }

        return static::where('instance', $instance)->first();
    }

    /**
     * Teto diário efetivo considerando o aquecimento: número novo começa baixo e
     * sobe a cada dia até atingir o daily_cap configurado.
     */
    public function effectiveDailyCap(): int
    {
        if ($this->daily_cap <= 0) {
            return 0;
        }
        // Rampa de aquecimento conservadora: 20 no 1º dia, +20/dia, até o teto.
        $warmCap = 20 * max(1, (int) $this->warmup_day);

        return min($this->daily_cap, $warmCap);
    }

    /** Quantos envios ainda cabem hoje neste número. */
    public function remainingToday(): int
    {
        $sent = $this->sent_date && $this->sent_date->isToday() ? $this->sent_today : 0;

        return max(0, $this->effectiveDailyCap() - $sent);
    }
}
