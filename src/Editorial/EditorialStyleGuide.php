<?php

namespace AxelFerdinand\StatamicSecretary\Editorial;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use AxelFerdinand\StatamicSecretary\Models\Setting;
use Illuminate\Support\Arr;
use Statamic\Facades\Site;

final class EditorialStyleGuide
{
    /** @var array<int, string> */
    private const FIELDS = ['audience', 'voice', 'terminology', 'avoid'];

    /** @return array<string, string> */
    public function forSite(?string $site): array
    {
        $site = $site ?: Site::default()->handle();
        $stored = (array) data_get($this->stored(), $site, []);

        return $this->normalize([
            ...(array) config('secretary.editorial.defaults', []),
            ...(array) config("secretary.editorial.sites.{$site}", []),
            ...$stored,
        ]);
    }

    /** @return array<int, array{handle: string, name: string, guide: array<string, string>}> */
    public function all(): array
    {
        return Site::all()->map(fn ($site): array => [
            'handle' => $site->handle(),
            'name' => $site->name(),
            'guide' => $this->forSite($site->handle()),
        ])->values()->all();
    }

    /** @param  array<string, mixed>  $guide */
    public function update(string $site, array $guide): void
    {
        if (! Site::get($site)) {
            throw new ContentOperationDenied('Det valgte nettstedet finnes ikke.');
        }

        $guides = $this->stored();
        $guides[$site] = $this->normalize($guide);

        Setting::query()->updateOrCreate(
            ['key' => 'editorial_style_guides'],
            ['value' => $guides],
        );
    }

    public function instructions(?string $site): string
    {
        $guide = array_filter($this->forSite($site), fn (string $value): bool => $value !== '');

        if ($guide === []) {
            return '';
        }

        $labels = [
            'audience' => 'Audience',
            'voice' => 'Voice and tone',
            'terminology' => 'Preferred terminology',
            'avoid' => 'Avoid',
        ];
        $lines = ['Follow this site-specific editorial guide:'];

        foreach ($guide as $key => $value) {
            $lines[] = ($labels[$key] ?? $key).': '.$value;
        }

        return implode("\n", $lines);
    }

    /** @return array<string, array<string, string>> */
    private function stored(): array
    {
        $value = Setting::query()->find('editorial_style_guides')?->value;

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $guide
     * @return array<string, string>
     */
    private function normalize(array $guide): array
    {
        return collect(self::FIELDS)->mapWithKeys(function (string $field) use ($guide): array {
            $value = Arr::get($guide, $field, '');

            return [$field => is_string($value) ? trim($value) : ''];
        })->all();
    }
}
