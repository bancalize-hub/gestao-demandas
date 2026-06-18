<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sinal leve de "algo mudou no CRM" — o front rebusca pela API autenticada
 * ao receber. Não trafega dados sensíveis (canal público, só um aviso).
 */
class CrmUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $kind = 'crm') {}

    public function broadcastOn(): Channel
    {
        return new Channel('crm');
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    public function broadcastWith(): array
    {
        return ['kind' => $this->kind];
    }
}
