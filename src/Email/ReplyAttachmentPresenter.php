<?php

namespace AxelFerdinand\StatamicSecretary\Email;

use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Models\Message;
use Statamic\Facades\Asset;
use Statamic\Facades\User;
use Throwable;

final class ReplyAttachmentPresenter
{
    /**
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     native_url: string
     * }>
     */
    public function present(Conversation $conversation, Message $reply): array
    {
        $inboundId = $reply->reply_to_message_id
            ?: data_get($reply->metadata, 'reply_to_message_id');
        $inbound = $conversation->messages()->whereKey($inboundId)->first();

        return $inbound ? $this->presentInbound($conversation, $inbound) : [];
    }

    /**
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     native_url: string
     * }>
     */
    public function presentInbound(Conversation $conversation, Message $inbound): array
    {
        $user = User::find($conversation->user_id);

        if (! $user || (string) $inbound->conversation_id !== (string) $conversation->id) {
            return [];
        }

        return collect((array) data_get($inbound->metadata, 'attachments', []))
            ->filter(fn (mixed $attachment): bool => is_array($attachment))
            ->map(function (array $attachment) use ($user): ?array {
                $id = trim((string) ($attachment['id'] ?? ''));

                if ($id === '') {
                    return null;
                }

                try {
                    $asset = Asset::find($id);

                    if (! $asset || ! $user->can('view', $asset)) {
                        return null;
                    }

                    $url = $asset->editUrl();

                    if (! is_string($url) || $url === '') {
                        return null;
                    }

                    return [
                        'id' => $id,
                        'name' => $this->displayName($attachment, (string) $asset->path()),
                        'native_url' => $url,
                    ];
                } catch (Throwable) {
                    return null;
                }
            })
            ->filter()
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{id: string, name: string, native_url: string}>  $attachments
     */
    public function appendToText(string $body, array $attachments): string
    {
        $body = trim($body);

        if ($attachments === []) {
            return $body;
        }

        $body .= "\n\nVedlegg i Statamic:";

        foreach ($attachments as $attachment) {
            $body .= "\n- {$attachment['name']}\n  {$attachment['native_url']}";
        }

        return $body;
    }

    /** @param  array<string, mixed>  $attachment */
    private function displayName(array $attachment, string $assetPath): string
    {
        $name = str_replace('\\', '/', trim((string) ($attachment['name'] ?? '')));
        $name = basename($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = basename($assetPath);
        }

        return mb_substr($name, 0, 255);
    }
}
