<?php

namespace AxelFerdinand\StatamicSecretary\Agent;

use AxelFerdinand\StatamicSecretary\Content\ChangeSetPublisher;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Models\Message;
use AxelFerdinand\StatamicSecretary\Support\PublicError;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User;
use Throwable;

final class ConversationService
{
    public function __construct(
        private readonly AgentOrchestrator $agent,
        private readonly ChangeSetPublisher $changes,
        private readonly PublicationIntentDetector $publicationIntent,
    ) {}

    /** @param  array<string, mixed>  $context */
    public function start(string $channel, User $user, ?string $email = null, ?string $externalThreadId = null, array $context = []): Conversation
    {
        return Conversation::create([
            'channel' => $channel,
            'external_thread_id' => $externalThreadId,
            'user_id' => $user->id(),
            'email' => $email,
            'status' => 'open',
            'context' => ['title' => 'Ny samtale', ...$context],
        ]);
    }

    /** @param  array<string, mixed>  $metadata */
    public function send(
        Conversation $conversation,
        string $body,
        User $user,
        string $channel,
        array $metadata = [],
        ?string $providerMessageId = null,
    ): Message {
        $message = $this->recordInbound($conversation, $body, $user, $channel, $metadata, $providerMessageId);

        return $this->respondTo($message, $user);
    }

    /** @param  array<string, mixed>  $metadata */
    public function recordInbound(
        Conversation $conversation,
        string $body,
        User $user,
        string $channel,
        array $metadata = [],
        ?string $providerMessageId = null,
    ): Message {
        $body = trim($body);
        $maximum = max(1, (int) config('secretary.limits.max_input_characters', 20000));

        if ($body === '' || mb_strlen($body) > $maximum) {
            throw new ContentOperationDenied("Meldingen må inneholde mellom 1 og {$maximum} tegn.");
        }

        if ((string) $conversation->user_id !== (string) $user->id()) {
            throw new ContentOperationDenied('Denne samtalen tilhører en annen bruker.');
        }

        $message = $conversation->messages()->create([
            'direction' => 'inbound',
            'channel' => $channel,
            'role' => 'user',
            'body' => $body,
            'provider_message_id' => $providerMessageId,
            'metadata' => $metadata,
        ]);

        if (data_get($conversation->context, 'title') === 'Ny samtale') {
            $conversation->update(['context' => [
                ...(array) $conversation->context,
                'title' => Str::limit(preg_replace('/\s+/', ' ', $body), 54),
            ]]);
        }

        return $message;
    }

    public function respondTo(Message $message, User $user): Message
    {
        $conversation = $message->conversation;

        if ($reply = $this->existingReply($message)) {
            if ($responseId = data_get($reply->metadata, 'openai_response_id')) {
                $conversation->update(['openai_response_id' => $responseId]);
            }

            if (! $message->processed_at) {
                $message->update(['processed_at' => now()]);
            }

            return $reply;
        }

        return $this->publicationIntent->matches($message->body)
            ? $this->publishLatestDraft($conversation, $message, $user)
            : $this->agent->respond($conversation, $message, $user);
    }

    public function publishLatestDraft(Conversation $conversation, Message $message, User $user): Message
    {
        if (! $user->can('publish with secretary')) {
            throw new ContentOperationDenied('Du har ikke tilgang til å publisere med Secretary.');
        }

        $recordedIds = (array) data_get($message->metadata, 'change_set_ids', []);

        if ($recordedIds !== []) {
            $recorded = $conversation->changeSets()->whereIn('id', $recordedIds)->get();

            if ($recorded->count() === 1 && $recorded->first()->status === 'published') {
                return $this->assistantMessage(
                    $conversation,
                    $message,
                    'Publisert: '.($recorded->first()->summary ?: $recorded->first()->resource_id),
                    ['change_set_ids' => [$recorded->first()->id]],
                );
            }
        }

        $drafts = $conversation->changeSets()->where('status', 'draft')->get();
        $requestedId = $this->publicationIntent->changeSetId($message->body);

        if ($requestedId) {
            $drafts = $drafts->where('id', $requestedId)->values();
        }

        if ($drafts->isEmpty()) {
            return $this->assistantMessage($conversation, $message, 'Jeg finner ikke noe upublisert Secretary-utkast i denne samtalen.');
        }

        if ($drafts->count() > 1) {
            $choices = $drafts->map(
                fn ($draft): string => '- '.$draft->id.' — '.($draft->summary ?: $draft->resource_id)
            )->implode("\n");

            return $this->assistantMessage(
                $conversation,
                $message,
                "Denne samtalen har flere utkast. Velg «Publiser» på riktig endringskort i kontrollpanelet, eller svar «Publiser ID»:\n{$choices}",
            );
        }

        $message->update(['metadata' => [
            ...(array) $message->metadata,
            'change_set_ids' => [$drafts->first()->id],
            'explicit_publish_action' => true,
        ]]);

        $changeSet = $this->changes->publish($drafts->first(), $user, 'Published via Statamic Secretary');

        return $this->assistantMessage(
            $conversation,
            $message,
            'Publisert: '.($changeSet->summary ?: $changeSet->resource_id),
            ['change_set_ids' => [$changeSet->id]],
        );
    }

    public function publishChangeSet(Conversation $conversation, string $changeSetId, User $user, string $channel = 'cp'): Message
    {
        if ((string) $conversation->user_id !== (string) $user->id() || ! $user->can('publish with secretary')) {
            throw new ContentOperationDenied('Du har ikke tilgang til å publisere denne endringen med Secretary.');
        }

        if ($conversation->messages()->where('direction', 'inbound')->whereNull('processed_at')->exists()) {
            throw new ContentOperationDenied('Vent til Secretary er ferdig med den aktive meldingen før du publiserer.');
        }

        $changeSet = $conversation->changeSets()->whereKey($changeSetId)->firstOrFail();
        $inbound = $conversation->messages()->create([
            'direction' => 'inbound',
            'channel' => $channel,
            'role' => 'user',
            'body' => 'Publiser endringen: '.($changeSet->summary ?: $changeSet->id),
            'metadata' => ['change_set_ids' => [$changeSet->id], 'explicit_publish_action' => true],
        ]);

        try {
            $published = $this->changes->publish($changeSet, $user, 'Published via Statamic Secretary');
        } catch (Throwable $exception) {
            $inbound->update([
                'processed_at' => now(),
                'metadata' => [
                    ...(array) $inbound->metadata,
                    'processing_error' => PublicError::message(
                        $exception,
                        'Secretary kunne ikke publisere endringen. Kontroller loggen og prøv igjen.',
                    ),
                ],
            ]);

            throw $exception;
        }

        return $this->assistantMessage(
            $conversation,
            $inbound,
            'Publisert: '.($published->summary ?: $published->resource_id),
            ['change_set_ids' => [$published->id]],
        );
    }

    /** @param  array<string, mixed>  $metadata */
    private function assistantMessage(Conversation $conversation, Message $inbound, string $body, array $metadata = []): Message
    {
        $reply = $conversation->messages()->create([
            'direction' => 'outbound',
            'channel' => $inbound->channel,
            'role' => 'assistant',
            'body' => $body,
            'reply_to_message_id' => $inbound->id,
            'metadata' => ['reply_to_message_id' => $inbound->id, ...$metadata],
            'processed_at' => now(),
        ]);

        $inbound->update(['processed_at' => now()]);

        return $reply;
    }

    private function existingReply(Message $message): ?Message
    {
        return $message->conversation->messages()
            ->where('direction', 'outbound')
            ->where('reply_to_message_id', $message->id)
            ->first()
            ?? $message->conversation->messages()
                ->where('direction', 'outbound')
                ->get()
                ->first(fn (Message $candidate): bool => data_get($candidate->metadata, 'reply_to_message_id') === $message->id);
    }
}
