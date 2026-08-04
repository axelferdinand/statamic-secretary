<?php

namespace AxelFerdinand\StatamicSecretary\Http\Controllers\Cp;

use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\Content\ChangePreviewService;
use AxelFerdinand\StatamicSecretary\Content\ChangeSetReviewService;
use AxelFerdinand\StatamicSecretary\Diagnostics\DoctorReport;
use AxelFerdinand\StatamicSecretary\Editorial\EditorialStyleGuide;
use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Jobs\ProcessCpMessage;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Postmark\PostmarkConnector;
use AxelFerdinand\StatamicSecretary\Relay\RelayConfiguration;
use AxelFerdinand\StatamicSecretary\Relay\RelayPairingClient;
use AxelFerdinand\StatamicSecretary\Support\PublicError;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Throwable;

final class SecretaryController extends CpController
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly Dispatcher $bus,
        private readonly EmailConfiguration $email,
        private readonly RelayConfiguration $relay,
        private readonly ChangeSetReviewService $reviews,
        private readonly ChangePreviewService $previews,
        private readonly EditorialStyleGuide $styleGuide,
        private readonly DoctorReport $doctor,
    ) {}

    public function index(?Conversation $conversation = null): Response
    {
        $user = $this->user();
        $this->ensureCanUse($user);

        if ($conversation) {
            $this->ensureOwnsConversation($conversation, $user);
        }

        $conversation ??= Conversation::query()
            ->where('user_id', $user->id())
            ->withMax('messages', 'id')
            ->latest('updated_at')
            ->orderByRaw('COALESCE(messages_max_id, secretary_conversations.id) DESC')
            ->first();

        return Inertia::render('statamic-secretary::Secretary', [
            'conversations' => Conversation::query()
                ->where('user_id', $user->id())
                ->withMax('messages', 'id')
                ->latest('updated_at')
                ->orderByRaw('COALESCE(messages_max_id, secretary_conversations.id) DESC')
                ->get()
                ->map(fn (Conversation $item): array => [
                    'id' => $item->id,
                    'title' => data_get($item->context, 'title', 'Conversation'),
                    'channel' => $item->channel,
                    'updated_at' => $item->updated_at?->toIso8601String(),
                    'url' => cp_route('secretary.show', $item),
                ])->values(),
            'conversation' => $conversation ? $this->conversationPayload($conversation, $user) : null,
            'can_publish' => $user->can('publish with secretary'),
            'configured' => filled(config('secretary.openai.api_key')),
            'email_enabled' => $this->email->enabled() || $this->relay->enabled(),
            'email_setup' => [
                ...$this->email->publicStatus(),
                'can_configure' => $user->can('configure secretary'),
                'setup_url' => cp_route('secretary.setup.postmark'),
            ],
            'relay_setup' => [
                ...$this->relay->publicStatus(),
                'can_configure' => $user->can('configure secretary'),
                'setup_url' => cp_route('secretary.setup.relay'),
                'request_code_url' => cp_route('secretary.setup.relay.request-code'),
                'suggested_sender' => $user->email(),
                'suggested_public_url' => $this->email->suggestedPublicUrl(),
            ],
            'success' => session('secretary_success'),
            'style_guides' => [
                'sites' => $this->styleGuide->all(),
                'can_configure' => $user->can('configure secretary'),
                'save_url' => cp_route('secretary.settings.editorial'),
            ],
            'diagnostics' => [
                'checks' => $this->doctor->checks($this->email, $this->relay),
                'can_configure' => $user->can('configure secretary'),
            ],
            'developer_mode' => (bool) config('secretary.developer.mode')
                && $user->can('configure secretary'),
            'max_input_characters' => max(1, (int) config('secretary.limits.max_input_characters', 20000)),
            'endpoints' => [
                'create' => cp_route('secretary.store'),
                'home' => cp_route('secretary.index'),
                'references' => cp_route('secretary.panel.references'),
            ],
        ]);
    }

    public function saveEditorialGuide(Request $request): RedirectResponse
    {
        $user = $this->user();
        abort_unless($user->can('configure secretary'), 403);
        $validated = $request->validate([
            'site' => ['required', 'string', 'max:100'],
            'audience' => ['nullable', 'string', 'max:1000'],
            'voice' => ['nullable', 'string', 'max:2000'],
            'terminology' => ['nullable', 'string', 'max:3000'],
            'avoid' => ['nullable', 'string', 'max:3000'],
        ]);

        $this->styleGuide->update((string) $validated['site'], $validated);

        return back()->with('secretary_success', 'The editorial guide has been saved.');
    }

    public function store(): RedirectResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $conversation = $this->conversations->start('cp', $user);

        return redirect()->to(cp_route('secretary.show', $conversation));
    }

    public function connectPostmark(Request $request, PostmarkConnector $postmark): RedirectResponse
    {
        $user = $this->user();
        abort_unless($user->can('configure secretary'), 403);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'public_url' => [
                'required',
                'url:https',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! $this->email->isPublicHttpsUrl($value)) {
                        $fail('The webhook URL must be a public HTTPS URL.');
                    }
                },
            ],
        ], [
            'email.required' => 'Enter the Secretary address.',
            'email.email' => 'The Secretary address must be a valid email address.',
            'public_url.required' => 'Enter the site’s public HTTPS URL.',
            'public_url.url' => 'The webhook URL must be a valid HTTPS URL.',
        ]);

        try {
            $postmark->connect($validated['email'], $validated['public_url']);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'postmark_setup' => PublicError::message(
                    $exception,
                    'Secretary could not connect to Postmark. Check the Server API Token and try again.',
                ),
            ]);
        }

        return redirect()->to(cp_route('secretary.index'))
            ->with('secretary_success', 'Postmark is connected. Set up the forwarding address shown below.');
    }

    public function connectRelay(Request $request, RelayPairingClient $pairing): RedirectResponse
    {
        $user = $this->user();
        abort_unless($user->can('configure secretary'), 403);
        abort_unless($this->relay->pairingAvailable(), 404);

        $validated = $request->validate([
            'pairing_code' => ['required', 'string', 'regex:/^pc_[A-Za-z0-9_-]{43}$/D'],
            'public_url' => [
                'required',
                'url:https',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! $this->email->isPublicHttpsUrl($value)) {
                        $fail('The webhook URL must be a public HTTPS URL.');
                    }
                },
            ],
        ], [
            'pairing_code.required' => 'Enter the one-time code.',
            'pairing_code.regex' => 'The one-time code is invalid or incomplete.',
            'public_url.required' => 'Enter the site’s public HTTPS URL.',
            'public_url.url' => 'The webhook URL must be a valid HTTPS URL.',
        ]);

        try {
            $settings = $pairing->connect($validated['pairing_code'], $validated['public_url']);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'relay_setup' => PublicError::message(
                    $exception,
                    'Secretary could not connect the shared address. Check the one-time code and try again.',
                ),
            ]);
        }

        return redirect()->to(cp_route('secretary.index'))
            ->with('secretary_success', 'The shared address is ready: '.$settings['address']);
    }

    public function requestRelayCode(Request $request, RelayPairingClient $pairing): RedirectResponse
    {
        $user = $this->user();
        abort_unless($user->can('configure secretary'), 403);
        abort_unless($this->relay->pairingAvailable(), 404);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ], [
            'email.required' => 'Enter the email address that will use Secretary.',
            'email.email' => 'The sender must be a valid email address.',
        ]);
        $email = mb_strtolower(trim($validated['email']));
        $sender = User::findByEmail($email);

        if (! $sender || ! $sender->can('use secretary')) {
            return back()->withErrors([
                'relay_email' => 'The address must belong to a Statamic user with access to Secretary.',
            ]);
        }

        $label = trim((string) config('app.name'));

        if ($label === '') {
            $label = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'Statamic site';
        }

        try {
            $pairing->requestCode($email, mb_substr($label, 0, 120));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'relay_setup' => PublicError::message(
                    $exception,
                    'Secretary could not send the one-time code. Try again shortly.',
                ),
            ]);
        }

        return redirect()->to(cp_route('secretary.index'))
            ->with('secretary_success', 'The one-time code was sent to '.$email.'.');
    }

    public function send(Request $request, Conversation $conversation): RedirectResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $this->ensureOwnsConversation($conversation, $user);

        if (blank(config('secretary.openai.api_key'))) {
            return back()->withErrors(['secretary' => 'OpenAI is not configured for Secretary.']);
        }

        $validated = $request->validate(['message' => ['required', 'string', 'max:'.config('secretary.limits.max_input_characters', 20000)]]);

        $message = null;

        try {
            $message = $this->conversations->recordInbound($conversation, $validated['message'], $user, 'cp');
            $job = new ProcessCpMessage($message->id);

            if (config('queue.default') === 'sync') {
                $this->bus->dispatchAfterResponse($job);
            } else {
                $this->bus->dispatch($job);
            }
        } catch (Throwable $exception) {
            report($exception);
            $error = PublicError::message($exception, 'Secretary could not start processing. Check the application log and try again.');

            if ($message && ! $message->processed_at) {
                $message->update([
                    'processed_at' => now(),
                    'metadata' => [
                        ...(array) $message->metadata,
                        'processing_error' => $error,
                    ],
                ]);
            }

            return back()->withErrors(['secretary' => $error]);
        }

        return redirect()->to(cp_route('secretary.show', $conversation));
    }

    public function publish(Conversation $conversation, string $changeSet): RedirectResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $this->ensureOwnsConversation($conversation, $user);

        try {
            $this->conversations->publishChangeSet($conversation, $changeSet, $user);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'secretary' => PublicError::message($exception, 'Secretary could not publish the change. Check the application log and try again.'),
            ]);
        }

        return redirect()->to(cp_route('secretary.show', $conversation));
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

        return response()->json([
            'change' => $this->changePayload($change->fresh(), $conversation, $user),
        ]);
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

    private function conversationPayload(Conversation $conversation, $user): array
    {
        $conversation->load(['messages', 'changeSets']);
        $latestInbound = $conversation->messages->where('direction', 'inbound')->last();
        $pendingPosition = 0;
        $messageLookup = $conversation->messages->keyBy('id');

        return [
            'id' => $conversation->id,
            'title' => data_get($conversation->context, 'title', 'Conversation'),
            'channel' => $conversation->channel,
            'processing' => $conversation->messages
                ->where('direction', 'inbound')
                ->contains(fn ($message): bool => $message->processed_at === null),
            'processing_error' => data_get($latestInbound?->metadata, 'processing_error'),
            'failed_message_body' => data_get($latestInbound?->metadata, 'processing_error')
                ? $latestInbound?->body
                : null,
            'send_url' => cp_route('secretary.messages.store', $conversation),
            'context' => $this->conversationContext($conversation),
            'messages' => $conversation->messages->map(function ($message) use ($user, &$pendingPosition, $messageLookup): array {
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
                $payload = [
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
                    'metadata' => $message->metadata ?: [],
                    'created_at' => $message->created_at?->toIso8601String(),
                ];

                if (! config('secretary.developer.mode') || ! $user->can('configure secretary')) {
                    unset($payload['metadata']['developer_trace']);
                }

                return $payload;
            })->values(),
            'changes' => $conversation->changeSets->map(
                fn ($change): array => $this->changePayload($change, $conversation, $user)
            )->values(),
        ];
    }

    private function changePayload($change, Conversation $conversation, $user): array
    {
        return [
            'id' => $change->id,
            'status' => $change->status,
            'operation' => $change->operation,
            'resource_type' => $change->resource_type,
            'entry_id' => $change->resource_id,
            'collection' => $change->collection,
            'site' => $change->site,
            'slug' => $change->slug,
            'summary' => $change->summary ?: trim($change->operation.' '.($change->slug ?: $change->resource_id)),
            'proposed_by_message_id' => $change->proposed_by_message_id,
            'patch' => $change->patch ?: [],
            'before' => $change->before ?: null,
            'after' => $change->after ?: null,
            'failure' => $change->failure,
            'native_url' => $this->nativeContentUrl($change, $user),
            'preview_available' => $change->resource_type === 'entry' && $change->status === 'draft',
            'preview_url' => cp_route('secretary.changes.preview', [$conversation, $change->id]),
            'review' => $this->reviews->present($change),
            'review_url' => cp_route('secretary.changes.review', [$conversation, $change->id]),
            'publish_url' => cp_route('secretary.changes.publish', [$conversation, $change->id]),
            'created_at' => $change->created_at?->toIso8601String(),
        ];
    }

    private function conversationContext(Conversation $conversation): ?array
    {
        $context = data_get($conversation->context, 'cp_context');

        return is_array($context) ? $context : null;
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
