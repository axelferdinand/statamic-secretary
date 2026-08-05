<?php

namespace AxelFerdinand\StatamicSecretary\Data;

use InvalidArgumentException;

final readonly class InboundAttachment
{
    /** @param  array<string, mixed>  $payload */
    public static function fromPostmark(array $payload): self
    {
        return self::fromEncoded([
            'name' => $payload['Name'] ?? null,
            'content_type' => $payload['ContentType'] ?? null,
            'content' => $payload['Content'] ?? null,
            'content_length' => $payload['ContentLength'] ?? null,
            'sha256' => null,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    public static function fromRelay(array $payload): self
    {
        return self::fromEncoded($payload);
    }

    private function __construct(
        public string $name,
        public string $mimeType,
        public string $base64Content,
        public int $size,
        public string $sha256,
        public int $width,
        public int $height,
    ) {}

    /** @param  array<string, mixed>  $payload */
    private static function fromEncoded(array $payload): self
    {
        $name = $payload['name'] ?? null;
        $mimeType = $payload['content_type'] ?? null;
        $base64 = $payload['content'] ?? null;
        $declaredSize = $payload['content_length'] ?? null;
        $declaredHash = $payload['sha256'] ?? null;

        if (! is_string($name)
            || $name === ''
            || mb_strlen($name) > 255
            || basename(str_replace('\\', '/', $name)) !== $name
            || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1
            || ! is_string($mimeType)
            || ! is_string($base64)
            || ! is_numeric($declaredSize)) {
            throw new InvalidArgumentException('The inbound image attachment is invalid.');
        }

        $allowed = self::allowedMimeTypes();
        $mimeType = mb_strtolower(trim(explode(';', $mimeType, 2)[0]));

        if (! isset($allowed[$mimeType])) {
            throw new InvalidArgumentException('Secretary accepts JPEG, PNG, and WebP image attachments only.');
        }

        $extension = mb_strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($extension, $allowed[$mimeType], true)) {
            throw new InvalidArgumentException('The image filename does not match its declared file type.');
        }

        $bytes = base64_decode($base64, true);
        $maximumBytes = max(1, (int) config('secretary.assets.max_attachment_bytes', 8_000_000));

        if (! is_string($bytes)
            || $bytes === ''
            || strlen($bytes) !== (int) $declaredSize
            || strlen($bytes) > $maximumBytes) {
            throw new InvalidArgumentException('The image attachment is empty, malformed, or too large.');
        }

        $image = @getimagesizefromstring($bytes);

        if (! is_array($image)
            || ($image['mime'] ?? null) !== $mimeType
            || (int) ($image[0] ?? 0) < 1
            || (int) ($image[1] ?? 0) < 1) {
            throw new InvalidArgumentException('The attachment is not a valid image of the declared type.');
        }

        $sha256 = hash('sha256', $bytes);

        if ($declaredHash !== null
            && (! is_string($declaredHash)
                || preg_match('/^[a-f0-9]{64}$/D', $declaredHash) !== 1
                || ! hash_equals($sha256, $declaredHash))) {
            throw new InvalidArgumentException('The image attachment checksum is invalid.');
        }

        return new self(
            name: $name,
            mimeType: $mimeType,
            base64Content: $base64,
            size: strlen($bytes),
            sha256: $sha256,
            width: (int) $image[0],
            height: (int) $image[1],
        );
    }

    public function bytes(): string
    {
        return base64_decode($this->base64Content, true) ?: '';
    }

    /** @return array<string, mixed> */
    public function relayPayload(): array
    {
        return [
            'name' => $this->name,
            'content_type' => $this->mimeType,
            'content' => $this->base64Content,
            'content_length' => $this->size,
            'sha256' => $this->sha256,
        ];
    }

    /** @return array<string, array<int, string>> */
    private static function allowedMimeTypes(): array
    {
        $configured = array_values(array_intersect(
            (array) config('secretary.assets.allowed_mime_types', []),
            ['image/jpeg', 'image/png', 'image/webp'],
        ));

        return array_intersect_key([
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
        ], array_flip($configured));
    }
}
