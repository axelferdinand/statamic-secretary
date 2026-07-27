<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretary\Agent\PublicationIntentDetector;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;

class PublicationIntentDetectorTest extends TestCase
{
    public function test_it_accepts_only_clear_immediate_publication_commands(): void
    {
        $detector = new PublicationIntentDetector;

        $this->assertTrue($detector->matches('Ja, publiser utkastet nå.'));
        $this->assertTrue($detector->matches('Publish it'));
        $this->assertTrue($detector->matches('Publiser endringen 01jx8w6x68g3a9hpv3d6t09c9m'));
        $this->assertSame('01jx8w6x68g3a9hpv3d6t09c9m', $detector->changeSetId('Publiser 01JX8W6X68G3A9HPV3D6T09C9M'));
        $this->assertFalse($detector->matches('Ikke publiser ennå'));
        $this->assertFalse($detector->matches('Kan vi publisere dette senere?'));
        $this->assertFalse($detector->matches('Endre teksten og publiser'));
    }
}
