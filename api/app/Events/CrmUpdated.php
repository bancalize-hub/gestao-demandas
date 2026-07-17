<?php

namespace App\Events;

use App\Support\Tenancy;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sinal leve de "algo mudou no CRM" — o front rebusca pela API autenticada
 * ao receber. Não trafega dados sensíveis (só um aviso com o tipo do que mudou).
 *
 * Canal PRIVADO por empresa: `crm.{companyId}`. Cada empresa só recebe os avisos
 * da sua própria operação. O companyId, quando não informado, vem do contexto de
 * tenancy atual (todo dispatch acontece dentro do escopo de uma empresa).
 */
class CrmUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?int $companyId;

    public function __construct(public string $kind = 'crm', ?int $companyId = null, public ?string $slug = null)
    {
        $this->companyId = $companyId ?? app(Tenancy::class)->id();
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('crm.'.($this->companyId ?? 0));
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    public function broadcastWith(): array
    {
        // slug identifica a conversa afetada — permite ao front dar patch em UMA
        // linha da lista em vez de re-baixar as centenas de conversas.
        return ['kind' => $this->kind, 'slug' => $this->slug];
    }
}
