<?php

namespace AxelFerdinand\StatamicSecretary\Agent;

final class PublicationIntentDetector
{
    public function matches(string $message): bool
    {
        $message = mb_strtolower(trim($message));

        if ($message === '' || preg_match('/\b(ikke|not|don\'t|do not|senere|later)\b/u', $message)) {
            return false;
        }

        return (bool) preg_match(
            '/^(?:ja[,.! ]+)?(?:publiser|publish|go live)(?:\s+(?:det|den|denne|utkastet|siden|endringen|endringene|nå|it|this|the|draft|page|change|changes|now))*(?:\s+[0-9a-hjkmnp-tv-z]{26})?[.!]?$/u',
            $message,
        );
    }

    public function changeSetId(string $message): ?string
    {
        return preg_match('/\b([0-9a-hjkmnp-tv-z]{26})\b/ui', $message, $matches)
            ? mb_strtolower($matches[1])
            : null;
    }
}
