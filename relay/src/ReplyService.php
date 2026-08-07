<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\MailTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\RelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Data\ClaimState;
use AxelFerdinand\StatamicSecretaryRelay\Data\OutboundReply;
use AxelFerdinand\StatamicSecretaryRelay\Data\ReplyOutcome;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Security\Signature;
use JsonException;
use Throwable;

final class ReplyService
{
    public function __construct(
        private readonly RelayStore $store,
        private readonly MailTransport $mail,
        private readonly RelayAddress $address,
        private readonly int $maximumClockSkew = 300,
        private readonly bool $subscriptionRequired = false,
    ) {}

    /** @param  array<string, string>  $headers */
    public function accept(
        array $headers,
        string $method,
        string $path,
        string $body,
        ?int $now = null,
    ): ReplyOutcome {
        $normalizedHeaders = array_change_key_case($headers, CASE_LOWER);
        $installationId = trim((string) ($normalizedHeaders['secretary-installation'] ?? ''));
        $installation = $this->store->installationById($installationId);

        if (! $installation || ! $installation->hasRelayAccess($this->subscriptionRequired, $now)) {
            throw new RelayRejected('Reply installation is not active.');
        }

        Signature::verify(
            $installation,
            $this->store,
            $headers,
            $method,
            $path,
            $body,
            $now,
            $this->maximumClockSkew,
        );
        $payload = $this->payload($body);
        $routeToken = (string) $payload['route_token'];
        $conversationToken = (string) $payload['conversation_token'];
        $recipient = mb_strtolower((string) $payload['recipient']);

        $routeInstallation = $this->store->installationByRouteToken($routeToken);

        if (! $routeInstallation
            || ! hash_equals($routeInstallation->id, $installation->id)) {
            throw new RelayRejected('Reply route does not belong to the installation.');
        }

        $conversation = $this->store->conversationByToken($conversationToken);

        if (! $conversation
            || ! hash_equals($conversation->installationId, $installation->id)
            || ! hash_equals($conversation->routeToken, $routeToken)
            || ! hash_equals(mb_strtolower($conversation->sender), $recipient)) {
            throw new RelayRejected('Reply conversation does not match its recipient and route.');
        }

        $inbound = $this->store->inboundDelivery((string) $payload['inbound_provider_message_id']);

        if (! $inbound
            || ! hash_equals($inbound->installationId, $installation->id)
            || ! hash_equals($inbound->routeToken, $routeToken)
            || ! hash_equals($inbound->conversationToken, $conversationToken)
            || ! hash_equals($inbound->sender, $recipient)) {
            throw new RelayRejected('Reply does not match the referenced inbound message.');
        }

        $idempotencyKey = (string) $payload['idempotency_key'];
        $claim = $this->store->claimReply($idempotencyKey, $installation->id, hash('sha256', $body));

        if ($claim === ClaimState::Conflict) {
            throw new RelayRejected('Reply idempotency key conflicts with an existing claim.');
        }

        if ($claim === ClaimState::Complete) {
            return new ReplyOutcome(true, $this->store->completedReplyProviderId($idempotencyKey, $installation->id));
        }

        if ($claim === ClaimState::Processing) {
            return new ReplyOutcome(true);
        }

        $reply = new OutboundReply(
            $idempotencyKey,
            $installation->id,
            $recipient,
            (string) $payload['subject'],
            (string) $payload['body'],
            $this->address->replyTo($routeToken, $conversationToken),
            $payload['in_reply_to'],
            $payload['review_url'],
            $payload['change_sets'],
            $payload['version'] === 2
                ? (string) $payload['locale']
                : (new ReplyLanguage)->detect((string) $payload['body']),
        );

        try {
            $providerMessageId = $this->mail->send($reply);

            if ($providerMessageId === '' || mb_strlen($providerMessageId) > 255) {
                throw new RelayRejected('Mail transport returned an invalid provider message ID.');
            }

            $this->store->completeReply($idempotencyKey, $installation->id, $providerMessageId);
        } catch (Throwable $exception) {
            $this->store->releaseReply($idempotencyKey, $installation->id);

            throw $exception;
        }

        return new ReplyOutcome(false, $providerMessageId);
    }

    /** @return array<string, mixed> */
    private function payload(string $body): array
    {
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RelayRejected('Reply payload is invalid JSON.', previous: $exception);
        }

        $version = is_array($payload) ? ($payload['version'] ?? null) : null;
        $allowed = [
            'version',
            'idempotency_key',
            'inbound_provider_message_id',
            'recipient',
            'subject',
            'body',
            'review_url',
            'change_sets',
            'route_token',
            'conversation_token',
            'in_reply_to',
        ];

        if ($version === 2) {
            $allowed[] = 'locale';
        }

        if (! is_array($payload)
            || array_diff(array_keys($payload), $allowed) !== []
            || array_diff($allowed, array_keys($payload)) !== []
            || ! in_array($version, [1, 2], true)
            || ! is_string($payload['idempotency_key'])
            || preg_match('/^secretary-reply-[a-z0-9_-]{20,180}$/D', $payload['idempotency_key']) !== 1
            || ! is_string($payload['inbound_provider_message_id'])
            || $payload['inbound_provider_message_id'] === ''
            || mb_strlen($payload['inbound_provider_message_id']) > 255
            || ! is_string($payload['recipient'])
            || filter_var(mb_strtolower($payload['recipient']), FILTER_VALIDATE_EMAIL) === false
            || ! is_string($payload['subject'])
            || mb_strlen($payload['subject']) > 180
            || ! is_string($payload['body'])
            || trim($payload['body']) === ''
            || mb_strlen($payload['body']) > 20000
            || ($version === 2 && (! is_string($payload['locale'] ?? null)
                || ! in_array($payload['locale'], [ReplyLanguage::ENGLISH, ReplyLanguage::NORWEGIAN], true)))
            || ! is_string($payload['route_token'])
            || preg_match('/^r[a-z0-9]{25}$/D', $payload['route_token']) !== 1
            || ! is_string($payload['conversation_token'])
            || preg_match('/^c[a-z0-9]{25}$/D', $payload['conversation_token']) !== 1
            || ! $this->validOptionalMessageId($payload['in_reply_to'])
            || ! $this->validOptionalReviewUrl($payload['review_url'])
            || ! $this->validChangeSets($payload['change_sets'])) {
            throw new RelayRejected('Reply payload failed validation.');
        }

        return $payload;
    }

    private function validOptionalMessageId(mixed $value): bool
    {
        return $value === null
            || (is_string($value) && preg_match('/^<[^<>\s@]+@[^<>\s@]+>$/D', $value) === 1);
    }

    private function validOptionalReviewUrl(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && mb_strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    private function validChangeSets(mixed $changeSets): bool
    {
        if (! is_array($changeSets) || count($changeSets) > 100) {
            return false;
        }

        foreach ($changeSets as $changeSet) {
            if (! is_array($changeSet)
                || array_diff(array_keys($changeSet), [
                    'id',
                    'status',
                    'summary',
                    'native_url',
                    'resource_title',
                    'public_url',
                ]) !== []
                || array_diff(['id', 'status', 'summary'], array_keys($changeSet)) !== []
                || ! is_string($changeSet['id'])
                || ! is_string($changeSet['status'])
                || ! in_array($changeSet['status'], ['draft', 'published', 'failed'], true)
                || ! is_string($changeSet['summary'])
                || mb_strlen($changeSet['summary']) > 500
                || ! $this->validOptionalReviewUrl($changeSet['native_url'] ?? null)
                || ! $this->validOptionalReviewUrl($changeSet['public_url'] ?? null)
                || ! $this->validOptionalResourceTitle($changeSet['resource_title'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function validOptionalResourceTitle(mixed $value): bool
    {
        return $value === null
            || (is_string($value) && trim($value) !== '' && mb_strlen($value) <= 500);
    }
}
