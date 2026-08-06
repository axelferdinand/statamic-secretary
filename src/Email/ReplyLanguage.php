<?php

namespace AxelFerdinand\StatamicSecretary\Email;

use AxelFerdinand\StatamicSecretary\Models\Message;

final class ReplyLanguage
{
    public const ENGLISH = 'en';

    public const NORWEGIAN = 'nb';

    public function forMessage(Message $message): string
    {
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
                'processing_failed' => 'Secretary kunne ikke behandle e-posten. Kontroller loggen eller åpne samtalen i kontrollpanelet og prøv igjen.',
                'processing_error' => 'Secretary kunne ikke behandle e-posten. Kontroller loggen og prøv igjen.',
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
            'processing_failed' => 'Secretary could not process the email. Check the log or open the conversation in the Control Panel and try again.',
            'processing_error' => 'Secretary could not process the email. Check the log and try again.',
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
