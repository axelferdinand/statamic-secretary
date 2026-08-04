<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final readonly class InboundAttachment
{
    /** @param  array<string, mixed>  $payload */
    public static function fromPostmark(array $payload, int $maximumBytes): self
    {
        $name = $payload['Name'] ?? null;
        $mimeType = $payload['ContentType'] ?? null;
        $base64 = $payload['Content'] ?? null;
        $declaredSize = $payload['ContentLength'] ?? null;

        if (! is_string($name)
            || $name === ''
            || mb_strlen($name) > 255
            || basename(str_replace('\\', '/', $name)) !== $name
            || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1
            || ! is_string($mimeType)
            || ! is_string($base64)
            || ! is_numeric($declaredSize)) {
            throw new RelayRejected('Postmark image attachment failed validation.');
        }

        $allowed = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
        ];
        $mimeType = mb_strtolower(trim(explode(';', $mimeType, 2)[0]));
        $extension = mb_strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        if (! isset($allowed[$mimeType]) || ! in_array($extension, $allowed[$mimeType], true)) {
            throw new RelayRejected('Postmark attachment type is not supported.');
        }

        $bytes = base64_decode($base64, true);

        if (! is_string($bytes)
            || $bytes === ''
            || strlen($bytes) !== (int) $declaredSize
            || strlen($bytes) > max(1, $maximumBytes)) {
            throw new RelayRejected('Postmark image attachment is empty, malformed, or too large.');
        }

        $image = @getimagesizefromstring($bytes);

        if (! is_array($image)
            || ($image['mime'] ?? null) !== $mimeType
            || (int) ($image[0] ?? 0) < 1
            || (int) ($image[1] ?? 0) < 1) {
            throw new RelayRejected('Postmark attachment is not a valid image of the declared type.');
        }

        return new self(
            name: $name,
            mimeType: $mimeType,
            base64Content: $base64,
            size: strlen($bytes),
            sha256: hash('sha256', $bytes),
        );
    }

    private function __construct(
        public string $name,
        public string $mimeType,
        public string $base64Content,
        public int $size,
        public string $sha256,
    ) {}

    /** @return array<string, mixed> */
    public function sitePayload(): array
    {
        return [
            'name' => $this->name,
            'content_type' => $this->mimeType,
            'content' => $this->base64Content,
            'content_length' => $this->size,
            'sha256' => $this->sha256,
        ];
    }
}
