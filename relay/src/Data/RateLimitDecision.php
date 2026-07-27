<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public int $resetAt,
    ) {}

    public function retryAfter(int $now): int
    {
        return max(1, $this->resetAt - $now);
    }
}
