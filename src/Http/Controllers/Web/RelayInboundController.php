<?php

namespace AxelFerdinand\StatamicSecretary\Http\Controllers\Web;

use AxelFerdinand\StatamicSecretary\Data\InboundEmail;
use AxelFerdinand\StatamicSecretary\Email\InboundEmailService;
use AxelFerdinand\StatamicSecretary\Relay\RelayConfiguration;
use AxelFerdinand\StatamicSecretary\Relay\RelaySignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

final class RelayInboundController extends Controller
{
    public function __construct(
        private readonly RelayConfiguration $configuration,
        private readonly RelaySignature $signature,
        private readonly InboundEmailService $inbound,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->configuration->enabled(), 403, 'Secretary shared-address relay is disabled.');
        $maximumBytes = max(1024, (int) config('secretary.limits.max_webhook_bytes', 2_000_000));
        abort_if(strlen($request->getContent()) > $maximumBytes, 403, 'The relay payload is too large.');
        $this->signature->verify($request);
        $allowedKeys = [
            'version',
            'provider_message_id',
            'sender',
            'subject',
            'body',
            'sender_authenticated',
            'spam_score',
            'route_token',
            'conversation_token',
            'rfc_message_id',
        ];
        abort_if(array_diff(array_keys($request->all()), $allowedKeys) !== [], 403, 'The relay payload contains unsupported fields.');
        $validator = Validator::make($request->all(), [
            'version' => ['required', 'integer', 'in:1'],
            'provider_message_id' => ['required', 'string', 'max:255'],
            'sender' => ['required', 'email:rfc', 'max:255'],
            'subject' => ['nullable', 'string', 'max:998'],
            'body' => ['required', 'string'],
            'sender_authenticated' => ['required', 'boolean'],
            'spam_score' => ['nullable', 'numeric'],
            'route_token' => ['required', 'string', 'regex:/^r[a-z0-9]{25}$/D'],
            'conversation_token' => ['nullable', 'string', 'regex:/^c[a-z0-9]{25}$/D'],
            'rfc_message_id' => ['nullable', 'regex:/^<[^<>\s@]+@[^<>\s@]+>$/D', 'max:998'],
        ]);
        abort_if($validator->fails(), 403, 'Invalid Secretary relay payload.');
        $payload = $validator->validated();
        $routeToken = (string) $payload['route_token'];
        abort_unless(
            $this->configuration->acceptsRouteToken(
                $routeToken,
                $payload['conversation_token'] ?? null,
            ),
            403,
            'Relay route does not match this site.',
        );
        $spamScore = isset($payload['spam_score']) ? (float) $payload['spam_score'] : null;
        abort_if($spamScore !== null && $spamScore > (float) config('secretary.email.max_spam_score', 5.0), 403, 'The inbound email exceeded Secretary\'s spam threshold.');
        abort_if(
            config('secretary.email.require_sender_authentication', true) && $payload['sender_authenticated'] !== true,
            403,
            'The inbound sender did not pass author-domain DKIM authentication.',
        );
        $result = $this->inbound->accept(new InboundEmail(
            providerMessageId: (string) $payload['provider_message_id'],
            sender: (string) $payload['sender'],
            body: (string) $payload['body'],
            subject: $payload['subject'] ?? null,
            senderAuthenticated: (bool) $payload['sender_authenticated'],
            spamScore: $spamScore,
            rfcMessageId: $payload['rfc_message_id'] ?? null,
            delivery: 'relay',
            threadToken: $payload['conversation_token'] ?? null,
            routeToken: $routeToken,
        ));

        return response()->json([
            'accepted' => true,
            'duplicate' => $result['duplicate'],
            'conversation_token' => data_get($result['message']->conversation?->context, 'relay_conversation_token'),
        ]);
    }
}
