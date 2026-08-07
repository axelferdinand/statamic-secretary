<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Symfony\Component\Process\Process;

class HostedRelayStandaloneAutoloadTest extends TestCase
{
    public function test_the_hosted_relay_does_not_depend_on_the_addon_autoloader(): void
    {
        $autoload = dirname(__DIR__, 2).'/relay/vendor/autoload.php';
        $process = new Process([
            PHP_BINARY,
            '-r',
            'require $argv[1]; exit(class_exists("AxelFerdinand\\StatamicSecretaryRelay\\ReplyLanguage") && ! class_exists("AxelFerdinand\\StatamicSecretary\\Email\\ReplyLanguage") ? 0 : 1);',
            $autoload,
        ]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }
}
