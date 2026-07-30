<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\PublicAliasProvisioner;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use CurlHandle;
use JsonException;

final readonly class CpanelPublicAliasProvisioner implements PublicAliasProvisioner
{
    public function __construct(
        private RelayAddress $address,
        private string $baseUrl,
        private string $username,
        private string $token,
        private string $postmarkInboundAddress,
    ) {
        $parts = parse_url(rtrim($baseUrl, '/'));

        if (! is_array($parts)
            || mb_strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || $username === ''
            || preg_match('/[\r\n\0:]/', $username) === 1
            || $token === ''
            || preg_match('/[\r\n\0]/', $token) === 1
            || filter_var($postmarkInboundAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new RelayRejected('Public email alias provisioning is not configured safely.');
        }
    }

    public function provision(Installation $installation): void
    {
        if (! PublicSiteAlias::valid($installation->publicAlias)) {
            throw new RelayRejected('Installation has no valid public email alias.');
        }

        $publicAddress = $this->address->publicAddress($installation->publicAlias);
        $target = $this->postmarkTarget($installation->routeToken);
        $existing = $this->forwarders();

        foreach ($existing as $forwarder) {
            if (! is_array($forwarder)
                || mb_strtolower((string) ($forwarder['dest'] ?? '')) !== $publicAddress) {
                continue;
            }

            if (mb_strtolower((string) ($forwarder['forward'] ?? '')) === $target) {
                return;
            }

            throw new RelayRejected('Public email alias already has another destination.');
        }

        $this->request('Email/add_forwarder', [
            'domain' => $this->address->domain(),
            'email' => $publicAddress,
            'fwdopt' => 'fwd',
            'fwdemail' => $target,
        ]);

        foreach ($this->forwarders() as $forwarder) {
            if (is_array($forwarder)
                && mb_strtolower((string) ($forwarder['dest'] ?? '')) === $publicAddress
                && mb_strtolower((string) ($forwarder['forward'] ?? '')) === $target) {
                return;
            }
        }

        throw new RelayTransientFailure('Public email alias could not be verified after provisioning.');
    }

    /** @return array<int, mixed> */
    private function forwarders(): array
    {
        $payload = $this->request('Email/list_forwarders', [
            'domain' => $this->address->domain(),
        ]);
        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new RelayTransientFailure('cPanel returned an invalid forwarder list.');
        }

        return array_values($data);
    }

    private function postmarkTarget(string $routeToken): string
    {
        if (preg_match('/^r[a-z0-9]{25}$/D', $routeToken) !== 1) {
            throw new RelayRejected('Installation route is invalid for email forwarding.');
        }

        [$local, $domain] = explode('@', mb_strtolower($this->postmarkInboundAddress), 2);
        $target = $local.'+'.$routeToken.'@'.$domain;

        if (filter_var($target, FILTER_VALIDATE_EMAIL) === false) {
            throw new RelayRejected('Postmark route destination is invalid.');
        }

        return $target;
    }

    /** @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function request(string $operation, array $query): array
    {
        $url = rtrim($this->baseUrl, '/').'/execute/'.$operation.'?'.http_build_query($query);
        $body = '';
        $tooLarge = false;
        $curl = curl_init();

        if (! $curl instanceof CurlHandle) {
            throw new RelayTransientFailure('cPanel request could not be initialized.');
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: cpanel '.$this->username.':'.$this->token,
            ],
            CURLOPT_WRITEFUNCTION => static function (
                CurlHandle $handle,
                string $chunk,
            ) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 1048576) {
                    $tooLarge = true;

                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl);

        if ($result === false || $error !== CURLE_OK) {
            if ($tooLarge) {
                throw new RelayRejected('cPanel response exceeded the size limit.');
            }

            throw new RelayTransientFailure('cPanel request failed.');
        }

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RelayTransientFailure('cPanel returned an invalid response.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RelayTransientFailure('cPanel returned an invalid response.');
        }

        if ($status >= 500) {
            throw new RelayTransientFailure('cPanel is temporarily unavailable.');
        }

        if ($status < 200 || $status >= 300 || ($payload['status'] ?? null) !== 1) {
            throw new RelayRejected('cPanel rejected public email alias provisioning.');
        }

        return $payload;
    }
}
