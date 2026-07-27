<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Security;

use AxelFerdinand\StatamicSecretaryRelay\Data\ResolvedHttpsUrl;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use Closure;

final class PublicHttpsUrl
{
    private readonly Closure $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver
            ? Closure::fromCallable($resolver)
            : static function (string $host): array {
                $records = dns_get_record($host, DNS_A | DNS_AAAA);
                $addresses = [];

                foreach (is_array($records) ? $records : [] as $record) {
                    $address = $record['ip'] ?? $record['ipv6'] ?? null;

                    if (is_string($address)) {
                        $addresses[] = $address;
                    }
                }

                return $addresses;
            };
    }

    public function resolve(string $url): ResolvedHttpsUrl
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw new RelayRejected('Relay HTTP destination is invalid.');
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? 443);

        if (mb_strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || $port !== 443
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (filter_var($host, FILTER_VALIDATE_IP) === false
                && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            throw new RelayRejected('Relay HTTP destination is invalid.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : ($this->resolver)($host);
        $addresses = array_values(array_unique(array_filter(
            is_array($addresses) ? $addresses : [],
            'is_string',
        )));

        if ($addresses === []) {
            throw new RelayRejected('Relay HTTP destination could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
                throw new RelayRejected('Relay HTTP destination is not public.');
            }
        }

        return new ResolvedHttpsUrl($url, $host, $port, $addresses);
    }
}
