<?php

namespace AxelFerdinand\StatamicSecretary\Email;

use AxelFerdinand\StatamicSecretary\Models\Message;

final class ReplyLanguage
{
    public const ENGLISH = 'en';

    public const NORWEGIAN = 'nb';

    public function forMessage(Message $message): string
    {
        $stored = data_get($message->metadata, 'reply_locale');

        if (in_array($stored, [self::ENGLISH, self::NORWEGIAN], true)) {
            return $stored;
        }

        $subject = trim((string) data_get($message->metadata, 'subject'));

        return $this->detect(trim($subject."\n".$message->body));
    }

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
                'attachments' => 'Vedlegg i Statamic',
                'open_page' => 'Åpne siden i Statamic',
                'open_draft' => 'Åpne utkastet i Statamic',
                'open_conversation' => 'Åpne samtalen i Secretary',
                'review_changes' => 'Se endringene i Secretary',
                'continue_conversation' => 'Fortsett samtalen i Secretary',
                'reply_to_continue' => 'Svar på denne e-posten for å fortsette samme samtale.',
                'publishing_disabled' => 'Publisering via e-post er deaktivert.',
                'sender_not_authenticated' => 'Avsenderen kunne ikke autentiseres for publisering via e-post.',
                'open_cp_to_publish' => 'Åpne Secretary i kontrollpanelet for å publisere utkastet.',
                'processing_failed' => 'Secretary traff et midlertidig problem. Forespørselen din er trygg. Åpne samtalen i kontrollpanelet, eller svar på nytt når problemet er løst.',
                'processing_error' => 'Secretary traff et midlertidig problem. Forespørselen din er trygg — prøv igjen når problemet er løst.',
                'openai_credits' => 'Secretary er koblet til OpenAI, men kontoen har ingen tilgjengelige kreditter. Be en administrator fylle på kreditter, og prøv igjen.',
                'openai_authentication' => 'Secretary får ikke autentisert seg mot OpenAI. Be en administrator koble til OpenAI-nøkkelen på nytt.',
                'openai_access' => 'OpenAI-kontoen har ikke tilgang til modellen Secretary bruker. Be en administrator kontrollere modell- og prosjekttilgangen.',
                'openai_rate_limit' => 'OpenAI begrenser forespørsler midlertidig. Vent litt, og prøv igjen.',
                'openai_connection' => 'Secretary fikk ikke kontakt med OpenAI. Prøv igjen om litt.',
                'openai_request' => 'OpenAI kunne ikke fullføre forespørselen. Prøv igjen; hvis det fortsetter, be en administrator kjøre systemkontrollen i Secretary.',
                'attached_image' => 'Vedlagt bilde',
                'acknowledgement_title' => 'Mottatt — jeg er på saken.',
                'acknowledgement_body' => 'Forespørselen er trygt plassert på Secretarys skrivebord. Jeg leser siden og sjekker strukturen nå — ingen kaffepause nødvendig. Jeg sender en ny e-post når arbeidet er klart til gjennomgang.',
                'nothing_to_publish' => 'Jeg finner ikke noe upublisert Secretary-utkast i denne samtalen.',
                'multiple_drafts' => 'Denne samtalen har flere utkast. Velg «Publiser» på riktig endringskort i kontrollpanelet, eller svar «Publiser ID»:',
                'published_prefix' => 'Publisert',
            ];
        }

        return [
            'affected_page' => 'Affected page',
            'prepared_changes' => 'Prepared changes',
            'published' => 'published',
            'draft' => 'draft',
            'attachments' => 'Attachments in Statamic',
            'open_page' => 'Open the page in Statamic',
            'open_draft' => 'Open the draft in Statamic',
            'open_conversation' => 'Open the conversation in Secretary',
            'review_changes' => 'Review the changes in Secretary',
            'continue_conversation' => 'Continue the conversation in Secretary',
            'reply_to_continue' => 'Reply to this email to continue the same conversation.',
            'publishing_disabled' => 'Publishing by email is disabled.',
            'sender_not_authenticated' => 'The sender could not be authenticated for publishing by email.',
            'open_cp_to_publish' => 'Open Secretary in the Control Panel to publish the draft.',
            'processing_failed' => 'Secretary hit a temporary problem. Your request is safe. Open the conversation in the Control Panel, or reply again after the issue is resolved.',
            'processing_error' => 'Secretary hit a temporary problem. Your request is safe—try again after the issue is resolved.',
            'openai_credits' => 'Secretary is connected to OpenAI, but that account has no available credits. Ask an administrator to add credits, then try again.',
            'openai_authentication' => 'Secretary cannot authenticate with OpenAI. Ask an administrator to reconnect the OpenAI API key.',
            'openai_access' => 'The connected OpenAI account cannot use Secretary’s selected model. Ask an administrator to check model and project access.',
            'openai_rate_limit' => 'OpenAI is temporarily rate-limiting requests. Wait a moment, then try again.',
            'openai_connection' => 'Secretary could not reach OpenAI. Try again shortly.',
            'openai_request' => 'OpenAI could not complete this request. Try again; if it keeps happening, ask an administrator to run Secretary’s system checks.',
            'attached_image' => 'Attached image',
            'acknowledgement_title' => 'Received — I’m on it.',
            'acknowledgement_body' => 'Your request is safely on Secretary’s desk. I’m reading the site and checking the structure now — no coffee break required. I’ll email you again when the work is ready to review.',
            'nothing_to_publish' => 'I could not find an unpublished Secretary draft in this conversation.',
            'multiple_drafts' => 'This conversation has several drafts. Choose “Publish” on the correct change card in the Control Panel, or reply with “Publish ID”:',
            'published_prefix' => 'Published',
        ];
    }

    /** @param  array<string, int>  $words */
    private function score(array $words, array $vocabulary): int
    {
        return array_sum(array_map(
            static fn (string $word): int => (int) ($words[$word] ?? 0),
            $vocabulary,
        ));
    }
}
