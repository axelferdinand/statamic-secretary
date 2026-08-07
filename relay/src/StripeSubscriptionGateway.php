<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SubscriptionGateway;
use AxelFerdinand\StatamicSecretaryRelay\Data\BillingCheckout;
use AxelFerdinand\StatamicSecretaryRelay\Data\BillingUpdate;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayAuthenticationFailed;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use JsonException;

final readonly class StripeSubscriptionGateway implements SubscriptionGateway
{
    public function __construct(
        private HttpTransport $http,
        private string $secretKey,
        private string $priceId,
        private string $webhookSecret,
        private int $webhookToleranceSeconds = 300,
    ) {
        if (preg_match('/^sk_(?:test|live)_[A-Za-z0-9_]+$/D', $secretKey) !== 1
            || preg_match('/^price_[A-Za-z0-9]+$/D', $priceId) !== 1
            || preg_match('/^whsec_[A-Za-z0-9]+$/D', $webhookSecret) !== 1
            || $webhookToleranceSeconds < 30
            || $webhookToleranceSeconds > 900) {
            throw new RelayRejected('Stripe subscription configuration is invalid.');
        }
    }

    public function createCheckout(Installation $installation): BillingCheckout
    {
        $origin = $this->siteOrigin($installation->webhookUrl);
        $response = $this->http->post(
            'https://api.stripe.com/v1/checkout/sessions',
            http_build_query([
                'mode' => 'subscription',
                'line_items' => [[
                    'price' => $this->priceId,
                    'quantity' => 1,
                ]],
                'customer_email' => $installation->senders[0] ?? null,
                'client_reference_id' => $installation->id,
                'metadata' => ['installation_id' => $installation->id],
                'subscription_data' => [
                    'metadata' => ['installation_id' => $installation->id],
                ],
                'success_url' => $origin.'/cp/secretary?relay_checkout=success',
                'cancel_url' => $origin.'/cp/secretary?relay_checkout=canceled',
            ], '', '&', PHP_QUERY_RFC3986),
            [
                'Authorization' => 'Bearer '.$this->secretKey,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        );

        if (! $response->successful()) {
            throw new RelayTransientFailure('Stripe could not create a subscription checkout.');
        }

        try {
            $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RelayTransientFailure('Stripe returned an invalid checkout response.', previous: $exception);
        }

        if (! is_array($payload)
            || ! is_string($payload['id'] ?? null)
            || ! is_string($payload['url'] ?? null)
            || ! is_int($payload['expires_at'] ?? null)) {
            throw new RelayTransientFailure('Stripe returned an incomplete checkout response.');
        }

        return new BillingCheckout($payload['id'], $payload['url'], $payload['expires_at']);
    }

    public function webhook(array $headers, string $body): ?BillingUpdate
    {
        if ($body === '' || strlen($body) > 1048576) {
            throw new RelayRejected('Stripe webhook payload is invalid.');
        }

        $this->verifyWebhook($headers, $body);

        try {
            $event = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RelayRejected('Stripe webhook payload is invalid JSON.', previous: $exception);
        }

        if (! is_array($event)
            || ! is_string($event['id'] ?? null)
            || ! is_string($event['type'] ?? null)
            || ! is_array($event['data']['object'] ?? null)) {
            throw new RelayRejected('Stripe webhook payload failed validation.');
        }

        $object = $event['data']['object'];
        $type = $event['type'];

        if (in_array($type, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
        ], true)) {
            $installationId = $this->installationId($object);
            $subscriptionId = $this->identifier($object['subscription'] ?? null, 'sub_');
            $customerId = $this->identifier($object['customer'] ?? null, 'cus_');
            $paymentStatus = is_string($object['payment_status'] ?? null)
                ? $object['payment_status']
                : '';

            return new BillingUpdate(
                $event['id'],
                $installationId,
                $subscriptionId,
                $customerId,
                in_array($paymentStatus, ['paid', 'no_payment_required'], true)
                    ? 'active'
                    : 'incomplete',
            );
        }

        if (in_array($type, [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ], true)) {
            $status = $type === 'customer.subscription.deleted'
                ? 'canceled'
                : (is_string($object['status'] ?? null) ? $object['status'] : '');

            if (! in_array($status, BillingUpdate::STATUSES, true)) {
                throw new RelayRejected('Stripe subscription status is invalid.');
            }

            return new BillingUpdate(
                $event['id'],
                $this->installationId($object),
                $this->identifier($object['id'] ?? null, 'sub_'),
                $this->identifier($object['customer'] ?? null, 'cus_'),
                $status,
                $this->periodEnd($object),
            );
        }

        return null;
    }

    /** @param  array<string, string>  $headers */
    private function verifyWebhook(array $headers, string $body): void
    {
        $normalized = array_change_key_case($headers, CASE_LOWER);
        $signature = trim((string) ($normalized['stripe-signature'] ?? ''));
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't' && is_string($value) && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null
            || abs(time() - $timestamp) > $this->webhookToleranceSeconds
            || $signatures === []) {
            throw new RelayAuthenticationFailed('Stripe webhook signature is invalid.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $this->webhookSecret);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw new RelayAuthenticationFailed('Stripe webhook signature is invalid.');
    }

    /** @param  array<string, mixed>  $object */
    private function installationId(array $object): ?string
    {
        $value = $object['metadata']['installation_id']
            ?? $object['client_reference_id']
            ?? null;

        return is_string($value) && preg_match('/^si_[a-z0-9_-]{20,125}$/D', $value) === 1
            ? $value
            : null;
    }

    private function identifier(mixed $value, string $prefix): ?string
    {
        return is_string($value) && preg_match('/^'.preg_quote($prefix, '/').'[A-Za-z0-9]+$/D', $value) === 1
            ? $value
            : null;
    }

    /** @param  array<string, mixed>  $object */
    private function periodEnd(array $object): ?int
    {
        if (is_int($object['current_period_end'] ?? null)) {
            return $object['current_period_end'];
        }

        $periods = $object['items']['data'] ?? null;
        $end = is_array($periods) ? ($periods[0]['current_period_end'] ?? null) : null;

        return is_int($end) ? $end : null;
    }

    private function siteOrigin(string $webhookUrl): string
    {
        $parts = parse_url($webhookUrl);

        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            throw new RelayRejected('Installation webhook URL is invalid.');
        }

        return 'https://'.$parts['host'];
    }
}
