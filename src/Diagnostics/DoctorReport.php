<?php

namespace AxelFerdinand\StatamicSecretary\Diagnostics;

use AxelFerdinand\StatamicSecretary\Developer\ToolRegistry;
use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Relay\RelayConfiguration;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory;
use Throwable;

final class DoctorReport
{
    public function __construct(private readonly ToolRegistry $tools) {}

    /** @return array<int, array{key: string, label: string, passed: bool, required: bool, details: string, success_details: string}> */
    public function checks(EmailConfiguration $email, RelayConfiguration $relay): array
    {
        $root = (string) (config('secretary.content.root') ?: base_path('content'));
        $emailEnabled = $email->enabled();
        $postmarkSetupPending = $email->tokenConfigured() && ! $email->connected() && blank(config('secretary.email.enabled'));

        return [
            $this->check('openai_key', 'OpenAI API key', filled(config('secretary.openai.api_key')), true, 'Set OPENAI_API_KEY.'),
            $this->check('openai_model', 'OpenAI model', filled(config('secretary.openai.model')), true, 'Set SECRETARY_OPENAI_MODEL.'),
            $this->check('content_root', 'Content root', is_dir($root) && is_writable($root), true, 'The configured content directory must exist and be writable.'),
            $this->check(
                'database',
                'Database tables',
                collect(['secretary_conversations', 'secretary_messages', 'secretary_change_sets', 'secretary_settings'])->every(fn (string $table): bool => Schema::hasTable($table))
                    && Schema::hasColumn('secretary_change_sets', 'live_base_fingerprint')
                    && Schema::hasColumn('secretary_change_sets', 'review')
                    && Schema::hasColumn('secretary_messages', 'reply_to_message_id'),
                true,
                'Run the addon migrations.',
            ),
            $this->check(
                'revisions',
                'Entry revisions',
                (bool) config('statamic.revisions.enabled'),
                false,
                'Published entry updates are refused until Statamic revisions are enabled.',
            ),
            $this->check(
                'queue',
                'Async queue',
                config('queue.default') !== 'sync',
                false,
                'Use a persistent queue worker for CP and inbound email in production.',
            ),
            $this->queueRetryWindowCheck(),
            $this->check(
                'relay',
                'Shared-address relay',
                ! $relay->enabled() || ($relay->configured() && $relay->hasValidBaseUrl()),
                $relay->enabled(),
                'Complete the relay installation ID, route token, 256-bit signing secret, and HTTPS base URL.',
                $relay->enabled() ? 'Ready' : 'Not configured.',
            ),
            $this->relayCacheCheck($relay),
            $this->check(
                'inbound_email',
                'Inbound email',
                $emailEnabled ? $this->emailIsComplete($email) : ! $postmarkSetupPending,
                $emailEnabled,
                $emailEnabled
                    ? 'Reconnect Postmark from Secretary in the Control Panel.'
                    : 'Open Secretary in the Control Panel to finish the Postmark connection.',
                $emailEnabled ? 'Ready' : ($email->tokenConfigured() ? 'Ready to connect in the Control Panel.' : 'Not configured.'),
            ),
            $this->check(
                'outbound_email',
                'Outbound email',
                $emailEnabled ? $this->outboundEmailIsComplete($email) : ! $postmarkSetupPending,
                $emailEnabled,
                $emailEnabled
                    ? 'The configured Secretary mailer cannot deliver. Reconnect Postmark or inspect the mailer override.'
                    : 'Finish the Postmark connection before testing outbound email.',
                $emailEnabled ? 'Ready' : ($email->tokenConfigured() ? 'Ready after Postmark setup.' : 'Not configured.'),
            ),
            $this->customToolCheck(),
            $this->webhookCheck(),
        ];
    }

    /** @return array{key: string, label: string, passed: bool, required: bool, details: string, success_details: string} */
    private function check(
        string $key,
        string $label,
        bool $passed,
        bool $required,
        string $details,
        string $successDetails = 'Ready',
    ): array {
        return compact('key', 'label', 'passed', 'required', 'details') + [
            'success_details' => $successDetails,
        ];
    }

    private function emailIsComplete(EmailConfiguration $email): bool
    {
        return $email->emailAddressesAreUsable()
            && filled($email->webhookUsername())
            && filled($email->webhookPassword())
            && collect(config('secretary.email.allowed_senders', []))->every(
                fn (mixed $address): bool => is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
            );
    }

    private function outboundEmailIsComplete(EmailConfiguration $email): bool
    {
        $mailer = $email->mailer();
        $configuration = config("mail.mailers.{$mailer}");

        if ($mailer === '' || ! is_array($configuration)) {
            return false;
        }

        $transport = (string) ($configuration['transport'] ?? $mailer);

        if (in_array($transport, ['array', 'log'], true)) {
            return false;
        }

        if ($transport === 'postmark' && (
            ! class_exists(PostmarkTransportFactory::class)
            || ! filled(
                $configuration['token']
                ?? $configuration['key']
                ?? config('services.postmark.key')
                ?? config('services.postmark.token')
            )
        )) {
            return false;
        }

        try {
            app('mail.manager')->mailer($mailer);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function queueRetryWindowCheck(): array
    {
        $connection = (string) config('queue.default');
        $retryAfter = config("queue.connections.{$connection}.retry_after");
        $jobTimeout = max(60, (int) config('secretary.limits.job_timeout', 1200));
        $passed = $connection === 'sync' || (is_numeric($retryAfter) && (int) $retryAfter > $jobTimeout);

        return $this->check(
            'queue_retry',
            'Queue retry window',
            $passed,
            false,
            "Set the [{$connection}] queue retry_after above Secretary's {$jobTimeout}-second job timeout.",
        );
    }

    private function relayCacheCheck(RelayConfiguration $relay): array
    {
        $store = $relay->cacheStore() ?: (string) config('cache.default');
        $driver = (string) config("cache.stores.{$store}.driver");

        return $this->check(
            'relay_cache',
            'Relay replay cache',
            ! $relay->enabled() || ($store !== '' && $driver !== '' && $driver !== 'array'),
            $relay->enabled(),
            'Use a persistent shared cache store for single-use relay nonces; the array store cannot prevent replay across requests.',
            $relay->enabled() ? 'Ready' : 'Not configured.',
        );
    }

    private function customToolCheck(): array
    {
        try {
            $count = count($this->tools->all());

            return $this->check(
                'developer_tools',
                'Developer tools',
                true,
                false,
                '',
                $count === 0 ? 'No extensions configured.' : "{$count} read-only extension(s) loaded.",
            );
        } catch (Throwable $exception) {
            return $this->check('developer_tools', 'Developer tools', false, true, $exception->getMessage());
        }
    }

    private function webhookCheck(): array
    {
        $enabled = (bool) config('secretary.developer.webhooks.enabled');
        $url = (string) config('secretary.developer.webhooks.url');
        $secret = (string) config('secretary.developer.webhooks.secret');
        $passed = ! $enabled || (str_starts_with($url, 'https://') && mb_strlen($secret) >= 32);

        return $this->check(
            'developer_webhooks',
            'Developer webhooks',
            $passed,
            $enabled,
            'Use an HTTPS webhook URL and a secret with at least 32 characters.',
            $enabled ? 'Ready' : 'Not configured.',
        );
    }
}
