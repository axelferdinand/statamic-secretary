<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Bootstrap;

use AxelFerdinand\StatamicSecretaryRelay\BillingNoticeService;
use AxelFerdinand\StatamicSecretaryRelay\CpanelPublicAliasProvisioner;
use AxelFerdinand\StatamicSecretaryRelay\CurlHttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\HostedRelayApplication;
use AxelFerdinand\StatamicSecretaryRelay\InboundRouter;
use AxelFerdinand\StatamicSecretaryRelay\Observability\SecurityEventReporter;
use AxelFerdinand\StatamicSecretaryRelay\PairingService;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteRelayStore;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkBillingNoticeTransport;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkInboundAdapter;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkMailTransport;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkPairingCodeTransport;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkSelectionTransport;
use AxelFerdinand\StatamicSecretaryRelay\RateLimiter;
use AxelFerdinand\StatamicSecretaryRelay\RelayAddress;
use AxelFerdinand\StatamicSecretaryRelay\ReplyService;
use AxelFerdinand\StatamicSecretaryRelay\Security\BasicAuth;
use AxelFerdinand\StatamicSecretaryRelay\Security\PublicHttpsUrl;
use AxelFerdinand\StatamicSecretaryRelay\SelectionService;
use AxelFerdinand\StatamicSecretaryRelay\SignedSiteTransport;
use AxelFerdinand\StatamicSecretaryRelay\StripeSubscriptionGateway;
use AxelFerdinand\StatamicSecretaryRelay\SubscriptionService;
use PDO;

final class RelayFactory
{
    public function application(): HostedRelayApplication
    {
        $store = $this->store();
        $sharedAddress = $this->optional('RELAY_SHARED_ADDRESS', 'secretary@statamic.no');
        $address = new RelayAddress($sharedAddress);
        $http = new CurlHttpTransport;
        $postmarkToken = $this->required('RELAY_POSTMARK_SERVER_TOKEN');
        $fromAddress = $this->optional('RELAY_FROM_ADDRESS', $sharedAddress);
        $fromName = $this->optional('RELAY_FROM_NAME', 'Secretary');
        $fromName = $fromName === 'Statamic Secretary' ? 'Secretary' : $fromName;
        $messageStream = $this->optional('RELAY_POSTMARK_MESSAGE_STREAM', 'outbound');
        $aliases = $this->publicAliasProvisioner($address);
        $subscriptions = $this->subscriptionService($store, $http);
        $subscriptionRequired = $subscriptions !== null;
        $postmark = new PostmarkMailTransport(
            $http,
            $postmarkToken,
            $fromAddress,
            $fromName,
            $messageStream,
        );
        $rateLimiter = new RateLimiter(
            $store,
            [
                'postmark_source' => $this->integer(
                    'RELAY_POSTMARK_RATE_LIMIT',
                    600,
                    1,
                    100000,
                ),
                'reply_source' => $this->integer(
                    'RELAY_REPLY_RATE_LIMIT',
                    300,
                    1,
                    100000,
                ),
                'pairing_source' => $this->integer(
                    'RELAY_PAIRING_RATE_LIMIT',
                    60,
                    1,
                    100000,
                ),
                'pairing_request_source' => $this->integer(
                    'RELAY_PAIRING_REQUEST_RATE_LIMIT',
                    10,
                    1,
                    100000,
                ),
                'pairing_recipient' => $this->integer(
                    'RELAY_PAIRING_RECIPIENT_RATE_LIMIT',
                    3,
                    1,
                    100000,
                ),
                'billing_source' => $this->integer(
                    'RELAY_BILLING_RATE_LIMIT',
                    300,
                    1,
                    100000,
                ),
            ],
            $this->integer('RELAY_RATE_LIMIT_WINDOW_SECONDS', 60, 10, 3600),
        );

        return new HostedRelayApplication(
            new BasicAuth(
                $this->required('RELAY_POSTMARK_WEBHOOK_USER'),
                $this->required('RELAY_POSTMARK_WEBHOOK_PASSWORD'),
            ),
            new PostmarkInboundAdapter(
                $sharedAddress,
                $this->boolean('RELAY_REQUIRE_SENDER_AUTHENTICATION', true),
                $this->float('RELAY_MAXIMUM_SPAM_SCORE', 5.0, -100.0, 100.0),
                $this->integer('RELAY_MAXIMUM_MESSAGE_CHARACTERS', 20000, 1000, 20000),
                $this->integer('RELAY_MAXIMUM_ATTACHMENTS', 4, 1, 10),
                $this->integer('RELAY_MAXIMUM_ATTACHMENT_BYTES', 8_000_000, 100_000, 20_000_000),
                $this->integer('RELAY_MAXIMUM_TOTAL_ATTACHMENT_BYTES', 16_000_000, 100_000, 50_000_000),
            ),
            new InboundRouter(
                $store,
                new SignedSiteTransport($http),
                $address,
                $this->boolean('RELAY_REQUIRE_SENDER_AUTHENTICATION', true),
                $this->float('RELAY_MAXIMUM_SPAM_SCORE', 5.0, -100.0, 100.0),
                $subscriptionRequired,
            ),
            new ReplyService(
                $store,
                $postmark,
                $address,
                $this->integer('RELAY_MAXIMUM_CLOCK_SKEW', 300, 30, 900),
                $subscriptionRequired,
            ),
            new SelectionService(
                $store,
                $store,
                new PostmarkSelectionTransport(
                    $http,
                    $postmarkToken,
                    $fromAddress,
                    $fromName,
                    $messageStream,
                ),
                $address,
                $aliases !== null,
                $subscriptionRequired,
            ),
            new PairingService($store, $address, new PublicHttpsUrl, $aliases, $subscriptions),
            new PostmarkPairingCodeTransport(
                $http,
                $postmarkToken,
                $fromAddress,
                $fromName,
                $messageStream,
            ),
            [SecurityEventReporter::class, 'report'],
            maximumRequestBytes: $this->integer(
                'RELAY_MAXIMUM_REQUEST_BYTES',
                24_000_000,
                32768,
                32_000_000,
            ),
            rateLimiter: $rateLimiter,
            subscriptions: $subscriptions,
            billingNotices: $subscriptions === null
                ? null
                : new BillingNoticeService(
                    $store,
                    $store,
                    $subscriptions,
                    new PostmarkBillingNoticeTransport(
                        $http,
                        $postmarkToken,
                        $fromAddress,
                        $fromName,
                        $messageStream,
                    ),
                ),
        );
    }

    public function store(): SqliteRelayStore
    {
        return new SqliteRelayStore(
            $this->pdo(),
            SqliteRelayStore::encryptionKeyFromBase64($this->required('RELAY_DATABASE_KEY')),
            leaseSeconds: $this->integer('RELAY_CLAIM_LEASE_SECONDS', 300, 30, 900),
        );
    }

    public function pdo(): PDO
    {
        $path = $this->required('RELAY_DATABASE_PATH');
        $directory = realpath(dirname($path));
        $public = realpath(__DIR__.'/../../public');
        $existing = file_exists($path) ? realpath($path) : null;

        if (! str_starts_with($path, '/')
            || ! is_string($directory)
            || ! is_dir($directory)
            || ! is_writable($directory)
            || is_link($path)
            || (file_exists($path) && (! is_string($existing) || ! is_file($existing)))
            || (is_string($public) && ($directory === $public || str_starts_with($directory, $public.'/')))) {
            throw new RelayRejected('Relay database path is invalid.');
        }

        return new PDO('sqlite:'.$path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function publicAliasProvisioner(
        ?RelayAddress $address = null,
    ): ?CpanelPublicAliasProvisioner {
        if (! $this->boolean('RELAY_FRIENDLY_ALIASES_ENABLED', false)) {
            return null;
        }

        $address ??= new RelayAddress(
            $this->optional('RELAY_SHARED_ADDRESS', 'secretary@statamic.no'),
        );

        return new CpanelPublicAliasProvisioner(
            $address,
            $this->required('RELAY_CPANEL_URL'),
            $this->required('RELAY_CPANEL_USER'),
            $this->required('RELAY_CPANEL_TOKEN'),
            $this->required('RELAY_POSTMARK_INBOUND_ADDRESS'),
        );
    }

    private function subscriptionService(
        SqliteRelayStore $store,
        CurlHttpTransport $http,
    ): ?SubscriptionService {
        $configuration = [
            'RELAY_STRIPE_SECRET_KEY' => trim((string) getenv('RELAY_STRIPE_SECRET_KEY')),
            'RELAY_STRIPE_PRICE_ID' => trim((string) getenv('RELAY_STRIPE_PRICE_ID')),
            'RELAY_STRIPE_WEBHOOK_SECRET' => trim((string) getenv('RELAY_STRIPE_WEBHOOK_SECRET')),
        ];
        $configured = array_filter($configuration, static fn (string $value): bool => $value !== '');

        if ($configured === []) {
            return null;
        }

        if (count($configured) !== count($configuration)) {
            throw new RelayRejected('Stripe relay billing configuration is incomplete.');
        }

        return new SubscriptionService(
            $store,
            new StripeSubscriptionGateway(
                $http,
                $configuration['RELAY_STRIPE_SECRET_KEY'],
                $configuration['RELAY_STRIPE_PRICE_ID'],
                $configuration['RELAY_STRIPE_WEBHOOK_SECRET'],
                $this->integer('RELAY_STRIPE_WEBHOOK_TOLERANCE', 300, 30, 900),
            ),
        );
    }

    private function required(string $key): string
    {
        $value = trim((string) getenv($key));

        if ($value === '') {
            throw new RelayRejected("Required relay environment value {$key} is missing.");
        }

        return $value;
    }

    private function optional(string $key, string $default): string
    {
        $value = trim((string) getenv($key));

        return $value !== '' ? $value : $default;
    }

    private function boolean(string $key, bool $default): bool
    {
        $value = getenv($key);

        if ($value === false || trim((string) $value) === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if (! is_bool($parsed)) {
            throw new RelayRejected("Relay environment value {$key} is invalid.");
        }

        return $parsed;
    }

    private function integer(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = getenv($key);

        if ($value === false || trim((string) $value) === '') {
            return $default;
        }

        if (preg_match('/^-?[0-9]+$/D', trim((string) $value)) !== 1) {
            throw new RelayRejected("Relay environment value {$key} is invalid.");
        }

        $parsed = (int) $value;

        if ($parsed < $minimum || $parsed > $maximum) {
            throw new RelayRejected("Relay environment value {$key} is out of range.");
        }

        return $parsed;
    }

    private function float(string $key, float $default, float $minimum, float $maximum): float
    {
        $value = getenv($key);

        if ($value === false || trim((string) $value) === '') {
            return $default;
        }

        if (! is_numeric($value)) {
            throw new RelayRejected("Relay environment value {$key} is invalid.");
        }

        $parsed = (float) $value;

        if (! is_finite($parsed) || $parsed < $minimum || $parsed > $maximum) {
            throw new RelayRejected("Relay environment value {$key} is out of range.");
        }

        return $parsed;
    }
}
