<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mensagem existente mudou (recibo sent/delivered/read, reação, transcrição,
 * remoção). Leva só os campos alterados — o front aplica na bolha certa sem
 * re-baixar a thread.
 */
class MessagePatched implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public ?int $companyId;

    public array $payload;

    public function __construct(Conversation $conversation, Message $message, array $patch)
    {
        $this->companyId = $conversation->company_id;
        $this->payload = array_merge([
            'slug' => $conversation->slug,
            'id' => $message->id,
            'wa_id' => $message->wa_id,
        ], $patch);
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('crm.'.($this->companyId ?? 0));
    }

    public function broadcastAs(): string
    {
        return 'message.patch';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
