<?php

namespace AxelFerdinand\StatamicSecretary\Http\Controllers\Cp;

use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\Jobs\ProcessCpMessage;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Support\PublicError;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Throwable;

final class SecretaryPanelController extends CpController
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly Dispatcher $bus,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $validated = $request->validate([
            'conversation_id' => ['nullable', 'string', 'max:64'],
        ]);
        $requestedId = trim((string) ($validated['conversation_id'] ?? ''));

        $conversation = $requestedId !== ''
            ? Conversation::query()
                ->whereKey($requestedId)
                ->where('user_id', $user->id())
                ->firstOrFail()
            : $this->latestConversation($user);

        return response()->json($this->panelPayload($user, $conversation));
    }

    public function store(): JsonResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $conversation = $this->conversations->start('cp', $user);

        return response()->json($this->panelPayload($user, $conversation), 201);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->user();
        $this->ensureCanUse($user);
        $this->ensureOwnsConversation($conversation, $user);

        if (blank(config('secretary.openai.api_key'))) {
            return response()->json(['message' => 'OpenAI er ikke konfigurert for Secretary.'], 422);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:'.config('secretary.limits.max_input_characters', 20000)],
        ]);
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

            return response()->json(['message' => $error], 500);
        }

        return response()->json($this->panelPayload($user, $conversation->fresh()), 202);
    }

    private function panelPayload($user, ?Conversation $conversation): array
    {
        return [
            'configured' => filled(config('secretary.openai.api_key')),
            'can_publish' => $user->can('publish with secretary'),
            'max_input_characters' => max(1, (int) config('secretary.limits.max_input_characters', 20000)),
            'home_url' => cp_route('secretary.index'),
            'create_url' => cp_route('secretary.panel.store'),
            'conversations' => $this->conversationList($user),
            'conversation' => $conversation ? $this->conversationPayload($conversation) : null,
        ];
    }

    private function conversationList($user)
    {
        return Conversation::query()
            ->where('user_id', $user->id())
            ->withMax('messages', 'id')
            ->latest('updated_at')
            ->orderByRaw('COALESCE(messages_max_id, secretary_conversations.id) DESC')
            ->get()
            ->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'title' => data_get($conversation->context, 'title', 'Samtale'),
                'channel' => $conversation->channel,
                'updated_at' => $conversation->updated_at?->toIso8601String(),
            ])->values();
    }

    private function conversationPayload(Conversation $conversation): array
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
            'send_url' => cp_route('secretary.panel.messages.store', $conversation),
            'full_url' => cp_route('secretary.show', $conversation),
            'messages' => $conversation->messages->map(fn ($message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'channel' => $message->channel,
                'body' => $message->body,
                'pending' => $message->direction === 'inbound' && $message->processed_at === null,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values(),
            'changes' => $conversation->changeSets->map(fn ($change): array => [
                'id' => $change->id,
                'status' => $change->status,
                'resource_type' => $change->resource_type,
                'collection' => $change->collection,
                'site' => $change->site,
                'summary' => $change->summary ?: trim($change->operation.' '.($change->slug ?: $change->resource_id)),
            ])->values(),
        ];
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
