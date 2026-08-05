<?php

namespace AxelFerdinand\StatamicSecretary\Assets;

use AxelFerdinand\StatamicSecretary\Data\InboundAttachment;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use Facades\Statamic\Fields\Validator as FieldValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Statamic\Assets\AssetUploader;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\Asset;
use Statamic\Rules\AllowedFile;

final class AttachmentImporter
{
    public function __construct(private readonly AllowedAssetContainers $allowedContainers) {}

    /**
     * @param  array<int, InboundAttachment>  $attachments
     * @return array<int, array<string, mixed>>
     */
    public function import(array $attachments, User $user): array
    {
        if ($attachments === []) {
            return [];
        }

        $maximumCount = max(1, min((int) config('secretary.assets.max_attachments', 4), 10));
        $maximumTotal = max(1, (int) config('secretary.assets.max_total_attachment_bytes', 16_000_000));

        if (count($attachments) > $maximumCount
            || array_sum(array_map(fn (InboundAttachment $attachment): int => $attachment->size, $attachments)) > $maximumTotal) {
            throw new ContentOperationDenied('The email contains too many images or exceeds Secretary\'s total attachment limit.');
        }

        $container = $this->allowedContainers->uploadContainer($user);

        return array_map(
            fn (InboundAttachment $attachment): array => $this->importOne($attachment, $container),
            $attachments,
        );
    }

    /** @return array<string, mixed> */
    private function importOne(InboundAttachment $attachment, mixed $container): array
    {
        $lock = Cache::lock(
            'secretary:asset-import:'.hash('sha256', $container->handle()."\0".$attachment->sha256),
            60,
        );

        return $lock->block(
            15,
            fn (): array => $this->importLocked($attachment, $container),
        );
    }

    /** @return array<string, mixed> */
    private function importLocked(InboundAttachment $attachment, mixed $container): array
    {
        $folder = $this->safeFolder((string) config('secretary.assets.attachment_folder', 'secretary-inbox'));
        $extension = match ($attachment->mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new ContentOperationDenied('Secretary accepts JPEG, PNG, and WebP image attachments only.'),
        };
        $originalBase = AssetUploader::getSafeFilename((string) pathinfo($attachment->name, PATHINFO_FILENAME));
        $originalBase = trim($originalBase, '.-') ?: 'image';
        $basename = $attachment->sha256.'-'.mb_substr($originalBase, 0, 80).'.'.$extension;
        $path = ($folder !== '' ? $folder.'/' : '').mb_substr($attachment->sha256, 0, 2).'/'.$basename;
        $id = $container->handle().'::'.$path;

        if ($container->disk()->exists($path)) {
            $existingBytes = $container->disk()->get($path);

            if (! is_string($existingBytes) || ! hash_equals($attachment->sha256, hash('sha256', $existingBytes))) {
                throw new ContentOperationDenied('Secretary refused to overwrite an existing asset path.');
            }

            $asset = Asset::find($id) ?? $container->makeAsset($path)->save();

            return $this->descriptor($asset, $attachment);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'secretary-asset-');

        if (! is_string($temporary) || file_put_contents($temporary, $attachment->bytes()) !== $attachment->size) {
            throw new RuntimeException('Secretary could not prepare the image attachment for import.');
        }

        try {
            $upload = new UploadedFile(
                $temporary,
                $attachment->name,
                $attachment->mimeType,
                null,
                true,
            );
            $rules = collect($container->validationRules())
                ->map(fn ($rule) => FieldValidator::parse($rule))
                ->all();
            Validator::make(['file' => $upload], [
                'file' => array_merge(['file', new AllowedFile], $rules),
            ])->validate();
            $asset = $container->makeAsset($path)->upload($upload);

            if (! $asset || ! hash_equals($path, (string) $asset->path())) {
                throw new ContentOperationDenied('Secretary could not import the image without changing its protected asset path.');
            }

            return $this->descriptor($asset, $attachment);
        } finally {
            @unlink($temporary);
        }
    }

    /** @return array<string, mixed> */
    private function descriptor(mixed $asset, InboundAttachment $attachment): array
    {
        return [
            'id' => $asset->id(),
            'container' => $asset->containerHandle(),
            'path' => $asset->path(),
            'name' => $attachment->name,
            'mime_type' => $attachment->mimeType,
            'size' => $attachment->size,
            'width' => $attachment->width,
            'height' => $attachment->height,
            'sha256' => $attachment->sha256,
        ];
    }

    private function safeFolder(string $folder): string
    {
        $folder = trim($folder, " \t\n\r\0\x0B/\\");
        $safe = AssetUploader::getSafePath($folder);

        if ($folder !== $safe || str_contains($safe, '..')) {
            throw new ContentOperationDenied('Secretary attachment folder is invalid.');
        }

        return $safe;
    }
}
