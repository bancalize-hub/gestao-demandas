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
 * Mensagem nova no chat — o evento CARREGA a mensagem e a linha da conversa
 * (payload do canal privado da empresa), então o front atualiza preview e thread
 * sem re-baixar nada pela API. ShouldBroadcastNow: sai direto pro Reverb.
 */
class MessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public array $conversation;

    public array $message;

    public ?int $companyId;

    public function __construct(Conversation $conversation, Message $message)
    {
        $this->companyId = $conversation->company_id;
        $this->conversation = $conversation->toArray();
        $this->message = $message->only(Message::THREAD_COLUMNS);
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('crm.'.($this->companyId ?? 0));
    }

    public function broadcastAs(): string
    {
        return 'message.new';
    }

    public function broadcastWith(): array
    {
        return ['conversation' => $this->conversation, 'message' => $this->message];
    }
}
