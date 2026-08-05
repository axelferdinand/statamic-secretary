<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Diagnostics\DoctorReport;
use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Relay\RelayConfiguration;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    public function test_it_reports_configuration_without_printing_the_api_key(): void
    {
        config()->set('secretary.openai.api_key', 'never-print-this-secret');

        $this->artisan('secretary:doctor')
            ->expectsOutputToContain('OpenAI API key')
            ->expectsOutputToContain('Not configured.')
            ->doesntExpectOutputToContain('never-print-this-secret')
            ->assertSuccessful();
    }

    public function test_the_default_sync_queue_needs_no_worker(): void
    {
        config()->set('queue.default', 'sync');

        $check = collect(app(DoctorReport::class)->checks(
            app(EmailConfiguration::class),
            app(RelayConfiguration::class),
        ))->firstWhere('key', 'queue');

        $this->assertTrue($check['passed']);
        $this->assertSame('Background processing', $check['label']);
        $this->assertSame('Built-in processing is active. No queue worker is required.', $check['success_details']);
    }

    public function test_missing_safe_drafts_are_a_blocking_problem(): void
    {
        config()->set('statamic.revisions.enabled', false);

        $this->artisan('secretary:doctor')
            ->expectsOutputToContain('Safe drafts')
            ->expectsOutputToContain('blocking configuration problems')
            ->assertFailed();
    }

    public function test_it_fails_when_required_configuration_is_missing(): void
    {
        config()->set('secretary.openai.api_key');

        $this->artisan('secretary:doctor')
            ->expectsOutputToContain('blocking configuration problems')
            ->assertFailed();
    }

    public function test_it_rejects_a_non_delivering_mailer_when_email_is_enabled(): void
    {
        $this->enableEmail();
        config()->set('mail.default', 'log');
        config()->set('mail.mailers.log', ['transport' => 'log']);

        $this->artisan('secretary:doctor')
            ->expectsOutputToContain('Outbound email')
            ->assertFailed();
    }

    public function test_it_accepts_an_instantiable_delivering_mailer(): void
    {
        $this->enableEmail();
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'host' => '127.0.0.1',
            'port' => 2525,
            'timeout' => 1,
        ]);

        $this->artisan('secretary:doctor')
            ->expectsOutputToContain('Outbound email')
            ->assertSuccessful();
    }

    public function test_it_rejects_a_pre_tagged_inbound_address_that_would_break_thread_hashes(): void
    {
        $this->enableEmail();
        config()->set('secretary.email.address', 'secretary+existing@example.com');
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'host' => '127.0.0.1',
            'port' => 2525,
        ]);

        $this->artisan('secretary:doctor')
            ->expectsOutputToContain('Inbound email')
            ->assertFailed();
    }

    public function test_it_rejects_an_enabled_relay_without_strong_site_credentials(): void
    {
        config()->set('secretary.relay.enabled', true);
        config()->set('secretary.relay.signing_secret', 'do-not-print-this');

        $this->artisan('secretary:doctor')
            ->expectsOutputToContain('Shared-address relay')
            ->doesntExpectOutputToContain('do-not-print-this')
            ->assertFailed();
    }

    public function test_it_accepts_a_complete_relay_with_a_persistent_replay_cache(): void
    {
        config()->set('secretary.relay.enabled', true);
        config()->set('secretary.relay.installation_id', 'si_'.str_repeat('a', 32));
        config()->set('secretary.relay.route_token', 'r'.str_repeat('a', 25));
        config()->set('secretary.relay.signing_secret', rtrim(strtr(base64_encode(str_repeat('s', 32)), '+/', '-_'), '='));
        config()->set('secretary.relay.base_url', 'https://secretary.statamic.no');
        config()->set('secretary.relay.cache_store', 'file');
        config()->set('cache.stores.file', [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ]);

        $this->artisan('secretary:doctor')
            ->expectsOutputToContain('Shared-address relay')
            ->expectsOutputToContain('Relay replay cache')
            ->assertSuccessful();
    }

    private function enableEmail(): void
    {
        config()->set('secretary.email.enabled', true);
        config()->set('secretary.email.address', 'secretary@example.com');
        config()->set('secretary.email.from_address', 'secretary@example.com');
        config()->set('secretary.email.allowed_senders', ['editor@example.com']);
        config()->set('secretary.email.postmark.username', 'webhook-user');
        config()->set('secretary.email.postmark.password', 'webhook-password');
    }
}
