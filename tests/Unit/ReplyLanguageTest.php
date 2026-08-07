<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretary\Email\ReplyLanguage;
use PHPUnit\Framework\TestCase;

class ReplyLanguageTest extends TestCase
{
    public function test_it_detects_norwegian_and_english_instructions(): void
    {
        $language = new ReplyLanguage;

        $this->assertSame(ReplyLanguage::NORWEGIAN, $language->detect('Forsiden: Kan du gjøre tittelen kortere?'));
        $this->assertSame(ReplyLanguage::ENGLISH, $language->detect('Homepage: Can you make the title shorter?'));
        $this->assertSame(ReplyLanguage::ENGLISH, $language->detect(''));
    }

    public function test_it_provides_matching_email_chrome(): void
    {
        $language = new ReplyLanguage;

        $this->assertSame('Berørt side', $language->copy(ReplyLanguage::NORWEGIAN)['affected_page']);
        $this->assertSame('Affected page', $language->copy(ReplyLanguage::ENGLISH)['affected_page']);
        $this->assertSame('Mottatt — jeg er på saken.', $language->copy(ReplyLanguage::NORWEGIAN)['acknowledgement_title']);
        $this->assertSame('Received — I’m on it.', $language->copy(ReplyLanguage::ENGLISH)['acknowledgement_title']);
        $this->assertSame(
            'Reply to this email to continue the same conversation.',
            $language->copy(ReplyLanguage::ENGLISH)['reply_to_continue'],
        );
    }
}
