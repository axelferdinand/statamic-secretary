<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentConflict;
use AxelFerdinand\StatamicSecretary\Support\PublicError;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use RuntimeException;

class PublicErrorTest extends TestCase
{
    public function test_it_preserves_domain_errors_but_hides_unexpected_internal_messages(): void
    {
        $this->assertSame(
            'Innholdet ble endret av en annen bruker.',
            PublicError::message(new ContentConflict('Innholdet ble endret av en annen bruker.'), 'Nøytral feil.'),
        );
        $this->assertSame(
            'Nøytral feil.',
            PublicError::message(new RuntimeException('redis://username:secret@internal.example'), 'Nøytral feil.'),
        );
    }
}
