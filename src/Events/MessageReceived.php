<?php

namespace AxelFerdinand\StatamicSecretary\Events;

use AxelFerdinand\StatamicSecretary\Contracts\SecretaryEvent;
use AxelFerdinand\StatamicSecretary\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

final class MessageReceived implements SecretaryEvent
{
    use Dispatchable;

    public function __construct(public readonly Message $message) {}

    public function name(): string
    {
        return 'message.received';
    }

    public function payload(): array
    {
        return [
            'message_id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'channel' => $this->message->channel,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
