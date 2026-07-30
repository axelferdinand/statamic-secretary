<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final class PublicSiteAlias
{
    public static function fromWebhookUrl(string $webhookUrl): string
    {
        $host = mb_strtolower(rtrim((string) parse_url($webhookUrl, PHP_URL_HOST), '.'));

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if ($host === ''
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^[a-z0-9.-]+$/D', $host) !== 1) {
            throw new RelayRejected('A public email alias could not be derived from the site URL.');
        }

        $alias = self::fit($host);

        if (! self::valid($alias)) {
            throw new RelayRejected('A public email alias could not be derived from the site URL.');
        }

        return $alias;
    }

    public static function withRouteSuffix(string $alias, string $routeToken): string
    {
        if (! self::valid($alias) || preg_match('/^r[a-z0-9]{25}$/D', $routeToken) !== 1) {
            throw new RelayRejected('A unique public email alias could not be generated.');
        }

        $suffix = '-'.substr($routeToken, 1, 8);
        $prefix = rtrim(substr($alias, 0, 64 - strlen($suffix)), '.-');
        $candidate = $prefix.$suffix;

        if (! self::valid($candidate)) {
            throw new RelayRejected('A unique public email alias could not be generated.');
        }

        return $candidate;
    }

    public static function valid(?string $alias): bool
    {
        return is_string($alias)
            && $alias !== ''
            && strlen($alias) <= 64
            && preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,62}[a-z0-9])?$/D', $alias) === 1
            && ! str_contains($alias, '..')
            && filter_var($alias.'@example.com', FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function fit(string $host): string
    {
        if (strlen($host) <= 64) {
            return $host;
        }

        $suffix = '-'.substr(hash('sha256', $host), 0, 12);
        $prefix = rtrim(substr($host, 0, 64 - strlen($suffix)), '.-');

        return $prefix.$suffix;
    }
}
