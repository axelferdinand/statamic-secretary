<?php

namespace AxelFerdinand\StatamicSecretary\Http\Controllers\Cp;

use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Jobs\ProcessCpMessage;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Postmark\PostmarkConnector;
use AxelFerdinand\StatamicSecretary\Relay\RelayConfiguration;
use AxelFerdinand\StatamicSecretary\Relay\RelayPairingClient;
use AxelFerdinand\StatamicSecretary\Support\PublicError;
use Illuminate\Contracts\Bus\Dispatcher;
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
                    'title' => data_get($item->context, 'title', 'Samtale'),
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
                'suggested_public_url' => $this->email->suggestedPublicUrl(),
            ],
            'success' => session('secretary_success'),
            'max_input_characters' => max(1, (int) config('secretary.limits.max_input_characters', 20000)),
            'endpoints' => [
                'create' => cp_route('secretary.store'),
                'home' => cp_route('secretary.index'),
            ],
        ]);
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
                        $fail('Webhook-adressen må være en offentlig HTTPS-adresse.');
                    }
                },
            ],
        ], [
            'email.required' => 'Skriv inn Secretary-adressen.',
            'email.email' => 'Secretary-adressen må være en gyldig e-postadresse.',
            'public_url.required' => 'Skriv inn nettstedets offentlige HTTPS-adresse.',
            'public_url.url' => 'Webhook-adressen må være en gyldig HTTPS-adresse.',
        ]);

        try {
            $postmark->connect($validated['email'], $validated['public_url']);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'postmark_setup' => PublicError::message(
                    $exception,
                    'Secretary kunne ikke koble til Postmark. Kontroller Server API Token og prøv igjen.',
                ),
            ]);
        }

        return redirect()->to(cp_route('secretary.index'))
            ->with('secretary_success', 'Postmark er koblet til. Sett opp videresendingen som vises nedenfor.');
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
                        $fail('Webhook-adressen må være en offentlig HTTPS-adresse.');
                    }
                },
            ],
        ], [
            'pairing_code.required' => 'Lim inn engangskoden.',
            'pairing_code.regex' => 'Engangskoden er ugyldig eller ufullstendig.',
            'public_url.required' => 'Skriv inn nettstedets offentlige HTTPS-adresse.',
            'public_url.url' => 'Webhook-adressen må være en gyldig HTTPS-adresse.',
        ]);

        try {
            $settings = $pairing->connect($validated['pairing_code'], $validated['public_url']);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'relay_setup' => PublicError::message(
                    $exception,
                    'Secretary kunne ikke koble til fellesadressen. Kontroller engangskoden og prøv igjen.',
                ),
            ]);
        }

        return redirect()->to(cp_route('secretary.index'))
            ->with('secretary_success', 'Fellesadressen er klar: '.$settings['address']);
    }

    public function send(Request $request, Conversation $conversation): RedirectResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $this->ensureOwnsConversation($conversation, $user);

        if (blank(config('secretary.openai.api_key'))) {
            return back()->withErrors(['secretary' => 'OpenAI er ikke konfigurert for Secretary.']);
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
            $error = PublicError::message($exception, 'Secretary kunne ikke starte behandlingen. Kontroller loggen og prøv igjen.');

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
                'secretary' => PublicError::message($exception, 'Secretary kunne ikke publisere endringen. Kontroller loggen og prøv igjen.'),
            ]);
        }

        return redirect()->to(cp_route('secretary.show', $conversation));
    }

    private function conversationPayload(Conversation $conversation, $user): array
    {
        $conversation->load(['messages', 'changeSets']);
        $latestInbound = $conversation->messages->where('direction', 'inbound')->last();

        return [
            'id' => $conversation->id,
            'title' => data_get($conversation->context, 'title', 'Samtale'),
            'channel' => $conversation->channel,
            'processing' => $conversation->messages
                ->where('direction', 'inbound')
                ->contains(fn ($message): bool => $message->processed_at === null),
            'processing_error' => data_get($latestInbound?->metadata, 'processing_error'),
            'send_url' => cp_route('secretary.messages.store', $conversation),
            'messages' => $conversation->messages->map(fn ($message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'channel' => $message->channel,
                'body' => $message->body,
                'pending' => $message->direction === 'inbound' && $message->processed_at === null,
                'metadata' => $message->metadata ?: [],
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values(),
            'changes' => $conversation->changeSets->map(fn ($change): array => [
                'id' => $change->id,
                'status' => $change->status,
                'operation' => $change->operation,
                'resource_type' => $change->resource_type,
                'entry_id' => $change->resource_id,
                'collection' => $change->collection,
                'site' => $change->site,
                'slug' => $change->slug,
                'summary' => $change->summary,
                'patch' => $change->patch ?: [],
                'before' => $change->before ?: null,
                'after' => $change->after ?: null,
                'failure' => $change->failure,
                'native_url' => $this->nativeContentUrl($change, $user),
                'publish_url' => cp_route('secretary.changes.publish', [$conversation, $change->id]),
                'created_at' => $change->created_at?->toIso8601String(),
            ])->values(),
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
