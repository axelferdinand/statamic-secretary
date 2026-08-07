<?php

namespace AxelFerdinand\StatamicSecretary\Http\Controllers\Cp;

use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\Content\ChangePreviewService;
use AxelFerdinand\StatamicSecretary\Content\ChangeSetReviewService;
use AxelFerdinand\StatamicSecretary\Content\EntryCatalog;
use AxelFerdinand\StatamicSecretary\Jobs\ProcessCpMessage;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\OpenAI\OpenAIConfiguration;
use AxelFerdinand\StatamicSecretary\Support\PublicError;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Throwable;

final class SecretaryPanelController extends CpController
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly Dispatcher $bus,
        private readonly ChangeSetReviewService $reviews,
        private readonly ChangePreviewService $previews,
        private readonly EntryCatalog $entries,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $validated = $request->validate([
            'conversation_id' => ['nullable', 'string', 'max:64'],
            'context_url' => ['nullable', 'url', 'max:2048'],
        ]);
        $requestedId = trim((string) ($validated['conversation_id'] ?? ''));
        $contextUrl = trim((string) ($validated['context_url'] ?? ''));
        $context = $this->entryContextFromUrl($contextUrl, $user);
        $activeContext = data_get($context, 'cp_context');

        if ($requestedId !== '') {
            $conversation = Conversation::query()
                ->whereKey($requestedId)
                ->where('user_id', $user->id())
                ->firstOrFail();
        } elseif (is_array($activeContext)) {
            $conversation = $this->latestConversationForContext($user, $activeContext);
        } else {
            $conversation = $contextUrl === '' ? $this->latestConversation($user) : null;
        }

        return response()->json($this->panelPayload(
            $user,
            $conversation,
            is_array($activeContext) ? $activeContext : null,
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $validated = $request->validate([
            'context_url' => ['nullable', 'url', 'max:2048'],
            'field_context' => ['nullable', 'array'],
            'field_context.handle' => ['nullable', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/D', 'max:100'],
            'field_context.set_type' => ['nullable', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/D', 'max:100'],
            'field_context.set_index' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
        $context = $this->entryContextFromUrl(
            (string) ($validated['context_url'] ?? ''),
            $user,
            (array) ($validated['field_context'] ?? []),
        );
        $conversation = $this->conversations->start('cp', $user, context: $context);

        return response()->json($this->panelPayload(
            $user,
            $conversation,
            $this->conversationContext($conversation),
        ), 201);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $this->ensureOwnsConversation($conversation, $user);

        if (! app(OpenAIConfiguration::class)->configured()) {
            return response()->json(['message' => 'OpenAI is not configured for Secretary.'], 422);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:'.config('secretary.limits.max_input_characters', 20000)],
            'context_url' => ['nullable', 'url', 'max:2048'],
            'field_context' => ['nullable', 'array'],
            'field_context.handle' => ['nullable', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/D', 'max:100'],
            'field_context.set_type' => ['nullable', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/D', 'max:100'],
            'field_context.set_index' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
        $message = null;

        try {
            $requestedContext = $this->entryContextFromUrl(
                (string) ($validated['context_url'] ?? ''),
                $user,
                (array) ($validated['field_context'] ?? []),
            );
            $activeContext = data_get($requestedContext, 'cp_context');

            if (is_array($activeContext)
                && ! $this->conversationMatchesContext($conversation, $activeContext)) {
                $conversation = $this->latestConversationForContext($user, $activeContext)
                    ?? $this->conversations->start('cp', $user, context: $requestedContext);
            }

            $this->refreshConversationContext($conversation, $requestedContext);
            $message = $this->conversations->recordInbound(
                $conversation,
                $validated['message'],
                $user,
                'cp',
            );
            $job = new ProcessCpMessage($message->id);

            if (config('queue.default') === 'sync') {
                $this->bus->dispatchAfterResponse($job);
            } else {
                $this->bus->dispatch($job);
            }
        } catch (Throwable $exception) {
            report($exception);
            $error = PublicError::message($exception, 'Secretary hit a temporary problem before the work could start. Your request is safe—try again.');

            if ($message && ! $message->processed_at) {
                $message->update([
                    'processed_at' => now(),
                    'metadata' => [
                        ...(array) $message->metadata,
                        'processing_error' => $error,
                    ],
                ]);
            }

            return response()->json(['message' => $error], 500);
        }

        return response()->json($this->panelPayload(
            $user,
            $conversation->fresh(),
            is_array($activeContext ?? null) ? $activeContext : $this->conversationContext($conversation),
        ), 202);
    }

    public function references(Request $request): JsonResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        return response()->json([
            'references' => collect($this->entries->search($user, $validated['q']))
                ->take(8)
                ->map(fn (array $entry): array => [
                    ...$entry,
                    'token' => '@['.($entry['title'] ?: $entry['slug']).'](entry:'.$entry['id'].')',
                ])->values(),
        ]);
    }

    public function publish(Conversation $conversation, string $changeSet): JsonResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $this->ensureOwnsConversation($conversation, $user);

        try {
            $this->conversations->publishChangeSet($conversation, $changeSet, $user);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => PublicError::message(
                    $exception,
                    'Secretary could not publish this change. Nothing was published. Try again.',
                ),
            ], 422);
        }

        return response()->json($this->panelPayload($user, $conversation->fresh()));
    }

    public function review(Request $request, Conversation $conversation, string $changeSet): JsonResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $this->ensureOwnsConversation($conversation, $user);
        $validated = $request->validate([
            'target' => ['required', 'string', 'max:255'],
            'decision' => ['required', 'string', 'in:pending,accepted,rejected'],
        ]);
        $change = $conversation->changeSets()->whereKey($changeSet)->firstOrFail();

        try {
            $this->reviews->decide($change, $validated['target'], $validated['decision'], $user);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => PublicError::message($exception, 'Secretary could not update the field selection.'),
            ], 422);
        }

        return response()->json($this->panelPayload($user, $conversation->fresh()));
    }

    public function preview(Conversation $conversation, string $changeSet): JsonResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $this->ensureOwnsConversation($conversation, $user);
        $change = $conversation->changeSets()->whereKey($changeSet)->firstOrFail();

        try {
            return response()->json($this->previews->urls($change, $user));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => PublicError::message($exception, 'The preview could not be opened.'),
            ], 422);
        }
    }

    /** @param  array<string, mixed>|null  $activeContext */
    private function panelPayload($user, ?Conversation $conversation, ?array $activeContext = null): array
    {
        $activeContext ??= $conversation ? $this->conversationContext($conversation) : null;

        return [
            'configured' => app(OpenAIConfiguration::class)->configured(),
            'can_publish' => $user->can('publish with secretary'),
            'max_input_characters' => max(1, (int) config('secretary.limits.max_input_characters', 20000)),
            'create_url' => cp_route('secretary.panel.store'),
            'references_url' => cp_route('secretary.panel.references'),
            'developer_mode' => (bool) config('secretary.developer.mode')
                && $user->can('configure secretary'),
            'draft_scope' => hash('sha256', (string) $user->id()),
            'active_context' => $activeContext,
            'active_context_key' => $this->contextKey($activeContext),
            'auto_open' => $conversation
                ? $this->conversationHasDraftForContext($conversation, $activeContext)
                : false,
            'background_jobs' => $this->backgroundJobs($user, $conversation),
            'conversations' => $this->conversationList($user, $activeContext),
            'conversation' => $conversation ? $this->conversationPayload($conversation, $user) : null,
        ];
    }

    /** @param  array<string, mixed>|null  $activeContext */
    private function conversationList($user, ?array $activeContext = null)
    {
        return Conversation::query()
            ->where('user_id', $user->id())
            ->with([
                'messages:id,conversation_id,direction,processed_at',
                'changeSets:id,conversation_id,resource_type,resource_id,site',
            ])
            ->withMax('messages', 'id')
            ->latest('updated_at')
            ->orderByRaw('COALESCE(messages_max_id, secretary_conversations.id) DESC')
            ->get()
            ->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'title' => data_get($conversation->context, 'title', 'Conversation'),
                'channel' => $conversation->channel,
                'context_key' => $this->contextKey($this->conversationContext($conversation)),
                'current_context' => $this->conversationRelatesToContext($conversation, $activeContext),
                'processing' => $conversation->messages
                    ->where('direction', 'inbound')
                    ->contains(fn ($message): bool => $message->processed_at === null),
                'updated_at' => $conversation->updated_at?->toIso8601String(),
            ])->values();
    }

    private function conversationPayload(Conversation $conversation, $user): array
    {
        $conversation->load(['messages', 'changeSets']);
        $latestInbound = $conversation->messages->where('direction', 'inbound')->last();
        $pendingPosition = 0;
        $channels = $conversation->messages->pluck('channel')->unique();
        $messageLookup = $conversation->messages->keyBy('id');

        return [
            'id' => $conversation->id,
            'title' => data_get($conversation->context, 'title', 'Conversation'),
            'channel' => $conversation->channel,
            'has_email_messages' => $channels->contains('email'),
            'has_cp_messages' => $channels->contains('cp'),
            'processing' => $conversation->messages
                ->where('direction', 'inbound')
                ->contains(fn ($message): bool => $message->processed_at === null),
            'processing_error' => data_get($latestInbound?->metadata, 'processing_error'),
            'failed_message_body' => data_get($latestInbound?->metadata, 'processing_error')
                ? $latestInbound?->body
                : null,
            'send_url' => cp_route('secretary.panel.messages.store', $conversation),
            'context' => $this->conversationContext($conversation),
            'messages' => $conversation->messages->map(function ($message) use ($user, &$pendingPosition, $messageLookup): array {
                $trace = config('secretary.developer.mode') && $user->can('configure secretary')
                    ? data_get($message->metadata, 'developer_trace')
                    : null;
                $pending = $message->direction === 'inbound' && $message->processed_at === null;
                $systemGenerated = data_get($message->metadata, 'system_generated') === true
                    || (
                        data_get($message->metadata, 'explicit_publish_action') === true
                        && str_starts_with($message->body, 'Publiser endringen:')
                    );
                $replySource = $message->reply_to_message_id
                    ? $messageLookup->get($message->reply_to_message_id)
                    : null;
                $replyToSystemAction = $replySource
                    && (
                        data_get($replySource->metadata, 'system_generated') === true
                        || (
                            data_get($replySource->metadata, 'explicit_publish_action') === true
                            && str_starts_with($replySource->body, 'Publiser endringen:')
                        )
                    );
                $systemEvent = data_get($message->metadata, 'system_event')
                    ?: ($replyToSystemAction ? 'published' : null);

                return [
                    'id' => $message->id,
                    'role' => $message->role,
                    'presentation' => $systemGenerated ? 'hidden' : ($systemEvent ? 'system' : $message->role),
                    'system_event' => $systemEvent,
                    'channel' => $message->channel,
                    'body' => $message->body,
                    'pending' => $pending,
                    'queue_position' => $pending ? ++$pendingPosition : null,
                    'processing_stage' => $pending
                        ? data_get($message->metadata, 'processing_stage', 'queued')
                        : null,
                    'reply_to_message_id' => $message->reply_to_message_id,
                    'developer_trace' => is_array($trace) ? $trace : null,
                    'created_at' => $message->created_at?->toIso8601String(),
                ];
            })->values(),
            'changes' => $conversation->changeSets->map(fn ($change): array => [
                'id' => $change->id,
                'status' => $change->status,
                'operation' => $change->operation,
                'resource_type' => $change->resource_type,
                'resource_id' => $change->resource_id,
                'collection' => $change->collection,
                'site' => $change->site,
                'slug' => $change->slug,
                'summary' => $change->summary ?: trim($change->operation.' '.($change->slug ?: $change->resource_id)),
                'proposed_by_message_id' => $change->proposed_by_message_id,
                'patch' => $change->patch ?: [],
                'before' => $change->before ?: null,
                'after' => $change->after ?: null,
                'failure' => $change->failure,
                'native_url' => $this->panelNativeContentUrl($change, $conversation, $user),
                'preview_available' => $change->resource_type === 'entry' && $change->status === 'draft',
                'preview_url' => cp_route('secretary.panel.changes.preview', [$conversation, $change->id]),
                'review' => $this->reviews->present($change),
                'review_url' => cp_route('secretary.panel.changes.review', [$conversation, $change->id]),
                'publish_url' => cp_route('secretary.panel.changes.publish', [$conversation, $change->id]),
            ])->values(),
        ];
    }

    /** @param  array<string, mixed>  $fieldContext */
    private function entryContextFromUrl(string $url, $user, array $fieldContext = []): array
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)
            || ! preg_match('#/collections/[^/]+/entries/([^/]+)(?:/[^/]*)?/?$#', $path, $matches)) {
            return [];
        }

        try {
            $entry = Entry::find(rawurldecode($matches[1]));

            if (! $entry || ! $user->can('view', $entry)) {
                return [];
            }

            $context = [
                'resource_type' => 'entry',
                'resource_id' => $entry->id(),
                'collection' => $entry->collection()->handle(),
                'site' => $entry->locale(),
                'title' => (string) ($entry->get('title') ?: $entry->slug()),
                'uri' => $entry->uri(),
                'edit_url' => $entry->editUrl(),
            ];
            $handle = (string) ($fieldContext['handle'] ?? '');

            if ($handle !== '') {
                $field = $entry->blueprint()->fields()->get($handle);

                if ($field && ! in_array($field->visibility(), ['computed', 'read_only'], true)) {
                    $context['field'] = [
                        'handle' => $field->handle(),
                        'display' => $field->display(),
                        'type' => $field->type(),
                    ];

                    if (in_array($field->type(), ['bard', 'replicator'], true)) {
                        $setType = (string) ($fieldContext['set_type'] ?? '');

                        if ($setType !== '') {
                            $context['field']['set_type'] = $setType;
                        }

                        if (isset($fieldContext['set_index'])) {
                            $context['field']['set_index'] = (int) $fieldContext['set_index'];
                        }
                    }
                }
            }

            return ['cp_context' => $context];
        } catch (Throwable) {
            return [];
        }
    }

    /** @param  array<string, mixed>  $context */
    private function refreshConversationContext(Conversation $conversation, array $context): void
    {
        $next = data_get($context, 'cp_context');

        if (! is_array($next)) {
            return;
        }

        $current = data_get($conversation->context, 'cp_context');

        if (is_array($current)
            && ($current['resource_id'] ?? null) !== ($next['resource_id'] ?? null)) {
            return;
        }

        $conversation->update([
            'context' => [
                ...(array) $conversation->context,
                'cp_context' => $next,
            ],
        ]);
    }

    private function conversationContext(Conversation $conversation): ?array
    {
        $context = data_get($conversation->context, 'cp_context');

        return is_array($context) ? $context : null;
    }

    /** @param  array<string, mixed>|null  $context */
    private function contextKey(?array $context): ?string
    {
        if (! is_array($context)
            || blank($context['resource_type'] ?? null)
            || blank($context['resource_id'] ?? null)) {
            return null;
        }

        return implode(':', [
            (string) $context['resource_type'],
            (string) ($context['site'] ?? ''),
            (string) $context['resource_id'],
        ]);
    }

    /** @param  array<string, mixed>|null  $context */
    private function conversationMatchesContext(Conversation $conversation, ?array $context): bool
    {
        $expected = $this->contextKey($context);

        return $expected !== null
            && hash_equals($expected, (string) $this->contextKey($this->conversationContext($conversation)));
    }

    /** @param  array<string, mixed>  $context */
    private function latestConversationForContext($user, array $context): ?Conversation
    {
        $query = Conversation::query()
            ->where('user_id', $user->id())
            ->where(function ($query) use ($context): void {
                $query->where(function ($query) use ($context): void {
                    $query
                        ->where('context->cp_context->resource_type', (string) $context['resource_type'])
                        ->where('context->cp_context->resource_id', (string) $context['resource_id'])
                        ->where('context->cp_context->site', (string) ($context['site'] ?? ''));
                })->orWhereHas('changeSets', fn ($query) => $query
                    ->where('resource_type', (string) $context['resource_type'])
                    ->where('resource_id', (string) $context['resource_id'])
                    ->where('site', (string) ($context['site'] ?? '')));
            });
        $draft = (clone $query)
            ->whereHas('changeSets', fn ($query) => $query
                ->where('status', 'draft')
                ->where('resource_type', (string) $context['resource_type'])
                ->where('resource_id', (string) $context['resource_id'])
                ->where('site', (string) ($context['site'] ?? '')));

        return $this->latestConversationFromQuery($draft)
            ?? $this->latestConversationFromQuery($query);
    }

    private function latestConversationFromQuery($query): ?Conversation
    {
        return $query
            ->withMax('messages', 'id')
            ->latest('updated_at')
            ->orderByRaw('COALESCE(messages_max_id, secretary_conversations.id) DESC')
            ->first();
    }

    /** @param  array<string, mixed>|null  $context */
    private function conversationHasDraftForContext(Conversation $conversation, ?array $context): bool
    {
        if (! is_array($context)) {
            return false;
        }

        return $conversation->changeSets()
            ->where('status', 'draft')
            ->where('resource_type', (string) $context['resource_type'])
            ->where('resource_id', (string) $context['resource_id'])
            ->where('site', (string) ($context['site'] ?? ''))
            ->exists();
    }

    /** @param  array<string, mixed>|null  $context */
    private function conversationRelatesToContext(Conversation $conversation, ?array $context): bool
    {
        if ($this->conversationMatchesContext($conversation, $context)) {
            return true;
        }

        if (! is_array($context)) {
            return false;
        }

        $changes = $conversation->relationLoaded('changeSets')
            ? $conversation->changeSets
            : $conversation->changeSets()->get(['id', 'conversation_id', 'resource_type', 'resource_id', 'site']);

        return $changes->contains(fn ($change): bool => $change->resource_type === ($context['resource_type'] ?? null)
            && (string) $change->resource_id === (string) ($context['resource_id'] ?? '')
            && (string) $change->site === (string) ($context['site'] ?? ''));
    }

    private function backgroundJobs($user, ?Conversation $selected)
    {
        return Conversation::query()
            ->where('user_id', $user->id())
            ->when($selected, fn ($query) => $query->whereKeyNot($selected->id))
            ->whereHas('messages', fn ($query) => $query
                ->where('direction', 'inbound')
                ->whereNull('processed_at'))
            ->with('changeSets')
            ->get()
            ->map(function (Conversation $conversation) use ($user): array {
                $context = $this->conversationContext($conversation)
                    ?? $this->contextFromLatestEntryChange($conversation, $user);

                return [
                    'id' => $conversation->id,
                    'title' => is_array($context)
                        ? (string) ($context['title'] ?? data_get($conversation->context, 'title', 'another page'))
                        : (string) data_get($conversation->context, 'title', 'another conversation'),
                    'context' => $context,
                ];
            })->values();
    }

    /** @return array<string, mixed>|null */
    private function contextFromLatestEntryChange(Conversation $conversation, $user): ?array
    {
        $change = $conversation->changeSets
            ->first(fn ($change): bool => $change->resource_type === 'entry');

        if (! $change) {
            return null;
        }

        $entry = Entry::find((string) $change->resource_id);

        if (! $entry || ! $user->can('view', $entry)) {
            return null;
        }

        return [
            'resource_type' => 'entry',
            'resource_id' => $entry->id(),
            'collection' => $entry->collection()->handle(),
            'site' => $entry->locale(),
            'title' => (string) ($entry->get('title') ?: $entry->slug()),
            'uri' => $entry->uri(),
            'edit_url' => $entry->editUrl(),
        ];
    }

    private function nativeContentUrl($change, $user): ?string
    {
        try {
            $resource = match ($change->resource_type) {
                'entry' => Entry::find((string) $change->resource_id),
                'term' => Term::find((string) $change->resource_id)?->in((string) $change->site),
                'global' => GlobalSet::findByHandle((string) $change->collection)?->in((string) $change->site),
                'navigation' => Nav::findByHandle((string) $change->collection)?->in((string) $change->site),
                default => null,
            };

            return $resource && $user->can('view', $resource) ? $resource->editUrl() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function panelNativeContentUrl($change, Conversation $conversation, $user): ?string
    {
        $url = $this->nativeContentUrl($change, $user);

        if (! $url
            || $change->resource_type !== 'entry'
            || $change->status !== 'draft') {
            return $url;
        }

        return $url
            .(str_contains($url, '?') ? '&' : '?')
            .'secretary='.rawurlencode((string) $conversation->id);
    }

    private function latestConversation($user): ?Conversation
    {
        return Conversation::query()
            ->where('user_id', $user->id())
            ->withMax('messages', 'id')
            ->latest('updated_at')
            ->orderByRaw('COALESCE(messages_max_id, secretary_conversations.id) DESC')
            ->first();
    }

    private function user()
    {
        return User::current() ?? abort(401);
    }

    private function ensureCanUse($user): void
    {
        abort_unless($user->can('use secretary'), 403);
    }

    private function ensureOwnsConversation(Conversation $conversation, $user): void
    {
        abort_unless((string) $conversation->user_id === (string) $user->id(), 404);
    }
}
