<?php

namespace AxelFerdinand\StatamicSecretary\Events;

use AxelFerdinand\StatamicSecretary\Contracts\SecretaryEvent;
use AxelFerdinand\StatamicSecretary\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

final class AgentCompleted implements SecretaryEvent
{
    use Dispatchable;

    public function __construct(public readonly Message $reply) {}

    public function name(): string
    {
        return 'agent.completed';
    }

    public function payload(): array
    {
        return [
            'message_id' => $this->reply->id,
            'conversation_id' => $this->reply->conversation_id,
            'channel' => $this->reply->channel,
            'change_set_ids' => array_values((array) data_get($this->reply->metadata, 'change_set_ids', [])),
            'usage' => (array) data_get($this->reply->metadata, 'usage', []),
            'created_at' => $this->reply->created_at?->toIso8601String(),
        ];
    }
}
