<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

/**
 * Relay-owned reply copy.
 *
 * The hosted relay is deployed as a standalone application and must never
 * depend on classes from the installable Statamic addon.
 */
final class ReplyLanguage
{
    public const ENGLISH = 'en';

    public const NORWEGIAN = 'nb';

    public function detect(string $text): string
    {
        $normalized = mb_strtolower($text);
        preg_match_all('/[\p{L}\p{N}]+/u', $normalized, $matches);
        $words = array_count_values($matches[0] ?? []);
        $norwegian = $this->score($words, [
            'jeg', 'du', 'deg', 'din', 'ditt', 'dine', 'denne', 'dette', 'kan', 'skal',
            'vil', 'ikke', 'med', 'fra', 'siden', 'forsiden', 'tittel', 'overskrift',
            'endre', 'endret', 'endring', 'oppdater', 'publiser', 'utkast', 'legg', 'fjern',
            'skriv', 'kortere', 'bilde', 'vedlegg', 'takk', 'og', 'eller', 'som', 'hva',
            'hvordan', 'klargjort', 'klart', 'teknisk',
        ]);
        $english = $this->score($words, [
            'i', 'you', 'your', 'this', 'that', 'can', 'should', 'want', 'not', 'with',
            'from', 'page', 'homepage', 'title', 'headline', 'change', 'changed', 'update',
            'publish', 'draft', 'add', 'remove', 'write', 'shorter', 'image', 'attachment',
            'thanks', 'and', 'or', 'which', 'what', 'how', 'ready',
        ]);

        if (preg_match('/[æøå]/u', $normalized) === 1) {
            $norwegian += 3;
        }

        return $norwegian > $english ? self::NORWEGIAN : self::ENGLISH;
    }

    /** @return array<string, string> */
    public function copy(string $locale): array
    {
        if ($locale === self::NORWEGIAN) {
            return [
                'affected_page' => 'Berørt side',
                'prepared_changes' => 'Klargjorte endringer',
                'published' => 'publisert',
                'draft' => 'utkast',
                'open_page' => 'Åpne siden i Statamic',
                'open_draft' => 'Åpne utkastet i Statamic',
                'continue_conversation' => 'Fortsett samtalen i Secretary',
                'reply_to_continue' => 'Svar på denne e-posten for å fortsette samme samtale.',
            ];
        }

        return [
            'affected_page' => 'Affected page',
            'prepared_changes' => 'Prepared changes',
            'published' => 'published',
            'draft' => 'draft',
            'open_page' => 'Open the page in Statamic',
            'open_draft' => 'Open the draft in Statamic',
            'continue_conversation' => 'Continue the conversation in Secretary',
            'reply_to_continue' => 'Reply to this email to continue the same conversation.',
        ];
    }

    /** @param array<string, int> $words */
    private function score(array $words, array $vocabulary): int
    {
        return array_sum(array_map(
            static fn (string $word): int => (int) ($words[$word] ?? 0),
            $vocabulary,
        ));
    }
}
