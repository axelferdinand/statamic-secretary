<?php

namespace AxelFerdinand\StatamicSecretary\Email;

use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\Agent\PublicationIntentDetector;
use AxelFerdinand\StatamicSecretary\Assets\AttachmentImporter;
use AxelFerdinand\StatamicSecretary\Data\InboundEmail;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use AxelFerdinand\StatamicSecretary\Jobs\ProcessInboundEmail;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Models\Message;
use AxelFerdinand\StatamicSecretary\OpenAI\OpenAIConfiguration;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Statamic\Facades\User;

final class InboundEmailService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly PublicationIntentDetector $publicationIntent,
        private readonly Dispatcher $bus,
        private readonly EmailConfiguration $email,
        private readonly AttachmentImporter $attachments,
    ) {}

    /** @return array{duplicate: bool, message: Message} */
    public function accept(InboundEmail $inbound): array
    {
        $sender = mb_strtolower(trim($inbound->sender));
        $this->ensureSenderIsNotOwnAddress($sender);

        if ($duplicate = $this->acceptDuplicate($inbound)) {
            return $duplicate;
        }

        abort_unless($this->email->senderIsAllowed($sender), 403, 'Sender is not allowed to use Secretary.');
        $user = User::findByEmail($sender);
        abort_unless($user && $user->can('use secretary'), 403, 'No authorized Statamic user matches the sender.');
        $body = trim($inbound->body);
        abort_if($body === '' && $inbound->attachments === [], 403, 'The inbound email has no readable body or supported image attachment.');
        $maximumCharacters = max(1, (int) config('secretary.limits.max_input_characters', 20000));
        abort_if(mb_strlen($body) > $maximumCharacters, 403, 'The inbound email instruction is too long.');
        abort_if(
            ! app(OpenAIConfiguration::class)->configured() && ! $this->publicationIntent->matches($body ?: 'Vedlagt bilde'),
            503,
            'Secretary OpenAI is not configured.',
        );

        try {
            $importedAttachments = $this->attachments->import($inbound->attachments, $user);
        } catch (ContentOperationDenied $exception) {
            abort(403, $exception->getMessage());
        }

        if ($body === '') {
            $body = 'Vedlagt bilde: '.implode(', ', array_column($importedAttachments, 'name'));
        }

        $conversation = $this->resolveConversation($inbound, $sender, $user);

        try {
            $message = $this->conversations->recordInbound(
                $conversation,
                $body,
                $user,
                'email',
                [
                    'subject' => $inbound->subject,
                    'sender_authenticated' => $inbound->senderAuthenticated,
                    'spam_score' => $inbound->spamScore,
                    'rfc_message_id' => $inbound->rfcMessageId,
                    'email_delivery' => $inbound->delivery,
                    'relay_route_token' => $inbound->routeToken,
                    'attachments' => $importedAttachments,
                ],
                $inbound->providerMessageId,
            );
        } catch (QueryException $exception) {
            if ($duplicate = Message::query()->where('provider_message_id', $inbound->providerMessageId)->first()) {
                $this->ensureDuplicateMatches($duplicate, $inbound, $sender);
                $this->dispatchMessage($duplicate);

                return ['duplicate' => true, 'message' => $duplicate];
            }

            throw $exception;
        }

        $this->dispatchMessage($message);

        return ['duplicate' => false, 'message' => $message];
    }

    /** @return array{duplicate: true, message: Message}|null */
    public function acceptDuplicate(InboundEmail $inbound): ?array
    {
        $this->ensureSenderIsNotOwnAddress(mb_strtolower(trim($inbound->sender)));
        $duplicate = Message::query()->where('provider_message_id', $inbound->providerMessageId)->first();

        if (! $duplicate) {
            return null;
        }

        $this->ensureDuplicateMatches($duplicate, $inbound, mb_strtolower(trim($inbound->sender)));
        $this->dispatchMessage($duplicate);

        return ['duplicate' => true, 'message' => $duplicate];
    }

    private function ensureSenderIsNotOwnAddress(string $sender): void
    {
        abort_if(
            $this->email->senderIsOwnAddress($sender),
            403,
            'Secretary cannot accept messages from its own outbound address.',
        );
    }

    private function dispatchMessage(Message $message): void
    {
        $job = new ProcessInboundEmail($message->id);

        if (config('queue.default') === 'sync') {
            $this->bus->dispatchAfterResponse($job);
        } else {
            $this->bus->dispatch($job);
        }
    }

    private function ensureDuplicateMatches(Message $message, InboundEmail $inbound, string $sender): void
    {
        $conversation = $message->conversation;
        $originalSender = mb_strtolower((string) $conversation?->email);
        abort_unless($originalSender !== '' && hash_equals($originalSender, $sender), 403, 'Duplicate sender does not match the original conversation.');

        $delivery = (string) data_get($conversation?->context, 'email_delivery', 'postmark');
        abort_unless(hash_equals($delivery, $inbound->delivery), 403, 'Duplicate delivery channel does not match the original conversation.');

        if ($inbound->delivery === 'relay') {
            $routeToken = (string) data_get($conversation?->context, 'relay_route_token');
            abort_unless($routeToken !== '' && hash_equals($routeToken, (string) $inbound->routeToken), 403, 'Duplicate relay route does not match the original conversation.');
        }
    }

    private function resolveConversation(InboundEmail $inbound, string $sender, $user): Conversation
    {
        $threadToken = trim((string) $inbound->threadToken);

        if ($threadToken !== '') {
            $conversation = $inbound->delivery === 'relay'
                ? Conversation::query()
                    ->where('channel', 'email')
                    ->where('context->relay_conversation_token', $threadToken)
                    ->first()
                : Conversation::query()->whereKey($threadToken)->where('channel', 'email')->first();

            abort_unless($conversation && hash_equals(mb_strtolower((string) $conversation->email), $sender), 403, 'The email thread could not be matched to this sender.');
            $this->ensureConversationRoute($conversation, $inbound);

            return $conversation;
        }

        $context = ['email_delivery' => $inbound->delivery];
        $externalThreadId = $inbound->providerMessageId;

        if ($inbound->delivery === 'relay') {
            $context['relay_route_token'] = (string) $inbound->routeToken;
            $context['relay_conversation_token'] = 'c'.Str::lower(Str::random(25));
            $externalThreadId = 'relay:'.hash('sha256', $inbound->providerMessageId);
        }

        try {
            return $this->conversations->start('email', $user, $sender, $externalThreadId, $context);
        } catch (QueryException $exception) {
            $conversation = Conversation::query()
                ->where('channel', 'email')
                ->where('external_thread_id', $externalThreadId)
                ->first();

            if ($conversation && hash_equals(mb_strtolower((string) $conversation->email), $sender)) {
                $this->ensureConversationRoute($conversation, $inbound);

                return $conversation;
            }

            throw $exception;
        }
    }

    private function ensureConversationRoute(Conversation $conversation, InboundEmail $inbound): void
    {
        $delivery = (string) data_get($conversation->context, 'email_delivery', 'postmark');
        abort_unless(hash_equals($delivery, $inbound->delivery), 403, 'The email thread belongs to another delivery channel.');

        if ($inbound->delivery === 'relay') {
            $routeToken = (string) data_get($conversation->context, 'relay_route_token');
            abort_unless($routeToken !== '' && hash_equals($routeToken, (string) $inbound->routeToken), 403, 'The email thread belongs to another relay route.');
        }
    }
}
