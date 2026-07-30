<?php

namespace AxelFerdinand\StatamicSecretary\Developer;

use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Models\Message;
use Statamic\Contracts\Auth\User;

final readonly class SecretaryToolContext
{
    /** @param  array<string, mixed>  $arguments */
    public function __construct(
        public array $arguments,
        public Conversation $conversation,
        public Message $message,
        public User $user,
    ) {}
}
