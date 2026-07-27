<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Data\ParsedAddress;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final class RelayAddress
{
    public function __construct(private readonly string $sharedAddress)
    {
        if (filter_var($sharedAddress, FILTER_VALIDATE_EMAIL) === false || str_contains(explode('@', $sharedAddress, 2)[0], '+')) {
            throw new RelayRejected('Shared relay address is invalid.');
        }
    }

    public function parse(string $recipient): ParsedAddress
    {
        $recipient = mb_strtolower(trim($recipient));

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RelayRejected('Relay recipient is invalid.');
        }

        [$sharedLocal, $sharedDomain] = explode('@', mb_strtolower($this->sharedAddress), 2);
        [$local, $domain] = explode('@', $recipient, 2);

        if (! hash_equals($sharedDomain, $domain)) {
            throw new RelayRejected('Relay recipient domain is invalid.');
        }

        if (hash_equals($sharedLocal, $local)) {
            return new ParsedAddress(null, null);
        }

        $prefix = $sharedLocal.'+';

        if (! str_starts_with($local, $prefix)) {
            throw new RelayRejected('Relay recipient local part is invalid.');
        }

        $tag = substr($local, strlen($prefix));
        [$routeToken, $conversationToken] = array_pad(explode('.', $tag, 2), 2, null);

        if (preg_match('/^r[a-z0-9]{25}$/D', (string) $routeToken) !== 1
            || ($conversationToken !== null && preg_match('/^c[a-z0-9]{25}$/D', $conversationToken) !== 1)) {
            throw new RelayRejected('Relay route alias is invalid.');
        }

        return new ParsedAddress($routeToken, $conversationToken);
    }

    public function replyTo(string $routeToken, string $conversationToken): string
    {
        $this->parse($this->taggedAddress($routeToken, $conversationToken));

        return $this->taggedAddress($routeToken, $conversationToken);
    }

    public function routeAddress(string $routeToken): string
    {
        [$local, $domain] = explode('@', mb_strtolower($this->sharedAddress), 2);
        $address = $local.'+'.mb_strtolower($routeToken).'@'.$domain;
        $this->parse($address);

        return $address;
    }

    private function taggedAddress(string $routeToken, string $conversationToken): string
    {
        [$local, $domain] = explode('@', mb_strtolower($this->sharedAddress), 2);

        return $local.'+'.mb_strtolower($routeToken).'.'.mb_strtolower($conversationToken).'@'.$domain;
    }
}
