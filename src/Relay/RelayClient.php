<?php

namespace AxelFerdinand\StatamicSecretary\Relay;

use AxelFerdinand\StatamicSecretary\Email\ReplyChangeSetPresenter;
use AxelFerdinand\StatamicSecretary\Exceptions\RelayDeliveryFailed;
use AxelFerdinand\StatamicSecretary\Models\Message;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class RelayClient
{
    public function __construct(
        private readonly RelayConfiguration $configuration,
        private readonly RelaySignature $signature,
        private readonly ReplyChangeSetPresenter $changeSets,
    ) {}

    public function sendReply(Message $inbound, Message $reply): void
    {
        $conversation = $inbound->conversation;
        $routeToken = (string) data_get($conversation?->context, 'relay_route_token');
        $conversationToken = (string) data_get($conversation?->context, 'relay_conversation_token');

        if (! $this->configuration->enabled()
            || ! $this->configuration->configured()
            || ! $this->configuration->hasValidBaseUrl()
            || ! $conversation
            || (string) $reply->conversation_id !== (string) $inbound->conversation_id
            || (string) $reply->reply_to_message_id !== (string) $inbound->id
            || data_get($conversation->context, 'email_delivery') !== 'relay'
            || $routeToken === ''
            || ! $this->configuration->acceptsRouteToken($routeToken, $conversationToken)
            || $conversationToken === '') {
            throw new RelayDeliveryFailed('Secretary relay reply configuration is invalid.');
        }

        try {
            $changeSets = $this->changeSets->present($conversation, $reply);
            $body = json_encode([
                'version' => 1,
                'idempotency_key' => 'secretary-reply-'.$reply->id,
                'inbound_provider_message_id' => (string) $inbound->provider_message_id,
                'recipient' => (string) $conversation->email,
                'subject' => $this->subject($conversation->messages()->where('channel', 'email')->oldest()->first()?->metadata),
                'body' => $this->changeSets->emailBody($reply->body, $changeSets),
                'review_url' => $this->changeSets->conversationUrl($conversation, $changeSets),
                'change_sets' => $changeSets,
                'route_token' => $routeToken,
                'conversation_token' => $conversationToken,
                'in_reply_to' => data_get($inbound->metadata, 'rfc_message_id'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RelayDeliveryFailed('Secretary could not encode the relay reply.', previous: $exception);
        }

        $endpoint = $this->configuration->replyEndpoint();
        $path = (string) parse_url($endpoint, PHP_URL_PATH);

        try {
            $response = Http::acceptJson()
                ->withHeaders($this->signature->headers('POST', $path, $body))
                ->withBody($body, 'application/json')
                ->connectTimeout(5)
                ->timeout(15)
                ->post($endpoint);
        } catch (ConnectionException $exception) {
            throw new RelayDeliveryFailed('Secretary could not reach the shared-address relay.', previous: $exception);
        } catch (Throwable $exception) {
            throw new RelayDeliveryFailed('Secretary could not deliver the relay reply.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RelayDeliveryFailed('The shared-address relay rejected the reply.');
        }
    }

    /** @param  mixed  $metadata */
    private function subject($metadata): string
    {
        $subject = Str::limit(preg_replace('/\s+/u', ' ', trim((string) data_get($metadata, 'subject'))) ?: '', 180, '');

        return str_starts_with(mb_strtolower($subject), 're:') ? $subject : 'Re: '.($subject ?: 'Statamic Secretary');
    }
}
