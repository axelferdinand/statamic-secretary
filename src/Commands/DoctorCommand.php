<?php

namespace AxelFerdinand\StatamicSecretary\Commands;

use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Relay\RelayConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'secretary:doctor';

    protected $description = 'Check Statamic Secretary configuration without exposing secrets';

    public function handle(EmailConfiguration $email, RelayConfiguration $relay): int
    {
        $root = (string) (config('secretary.content.root') ?: base_path('content'));
        $emailEnabled = $email->enabled();
        $postmarkSetupPending = $email->tokenConfigured() && ! $email->connected() && blank(config('secretary.email.enabled'));
        $rows = [
            $this->check('OpenAI API key', filled(config('secretary.openai.api_key')), true, 'Set OPENAI_API_KEY.'),
            $this->check('OpenAI model', filled(config('secretary.openai.model')), true, 'Set SECRETARY_OPENAI_MODEL.'),
            $this->check('Content root', is_dir($root) && is_writable($root), true, 'The configured content directory must exist and be writable.'),
            $this->check(
                'Database tables',
                collect(['secretary_conversations', 'secretary_messages', 'secretary_change_sets', 'secretary_settings'])->every(fn (string $table): bool => Schema::hasTable($table))
                    && Schema::hasColumn('secretary_change_sets', 'live_base_fingerprint')
                    && Schema::hasColumn('secretary_messages', 'reply_to_message_id'),
                true,
                'Run the addon migrations.',
            ),
            $this->check(
                'Entry revisions',
                (bool) config('statamic.revisions.enabled'),
                false,
                'Published entry updates are refused until Statamic revisions are enabled.',
            ),
            $this->check(
                'Async queue',
                config('queue.default') !== 'sync',
                false,
                'Use a persistent queue worker for CP and inbound email in production.',
            ),
            $this->queueRetryWindowCheck(),
            $this->check(
                'Shared-address relay',
                ! $relay->enabled() || ($relay->configured() && $relay->hasValidBaseUrl()),
                $relay->enabled(),
                'Complete the relay installation ID, route token, 256-bit signing secret, and HTTPS base URL.',
                $relay->enabled() ? 'Ready' : 'Not configured.',
            ),
            $this->relayCacheCheck($relay),
            $this->check(
                'Inbound email',
                $emailEnabled ? $this->emailIsComplete($email) : ! $postmarkSetupPending,
                $emailEnabled,
                $emailEnabled
                    ? 'Reconnect Postmark from Secretary in the Control Panel.'
                    : 'Open Secretary in the Control Panel to finish the Postmark connection.',
                $emailEnabled ? 'Ready' : ($email->tokenConfigured() ? 'Ready to connect in the Control Panel.' : 'Not configured.'),
            ),
            $this->check(
                'Outbound email',
                $emailEnabled ? $this->outboundEmailIsComplete($email) : ! $postmarkSetupPending,
                $emailEnabled,
                $emailEnabled
                    ? 'The configured Secretary mailer cannot deliver. Reconnect Postmark or inspect the mailer override.'
                    : 'Finish the Postmark connection before testing outbound email.',
                $emailEnabled ? 'Ready' : ($email->tokenConfigured() ? 'Ready after Postmark setup.' : 'Not configured.'),
            ),
        ];

        $this->table(['Check', 'Status', 'Details'], collect($rows)->map(fn (array $row): array => [
            $row['label'],
            $row['passed'] ? '<info>OK</info>' : ($row['required'] ? '<error>FAIL</error>' : '<comment>WARN</comment>'),
            $row['passed'] ? $row['success_details'] : $row['details'],
        ])->all());

        if (collect($rows)->contains(fn (array $row): bool => $row['required'] && ! $row['passed'])) {
            $this->newLine();
            $this->error('Secretary has blocking configuration problems.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Secretary is ready. Warnings describe optional or production-specific hardening.');

        return self::SUCCESS;
    }

    /** @return array{label: string, passed: bool, required: bool, details: string, success_details: string} */
    private function check(
        string $label,
        bool $passed,
        bool $required,
        string $details,
        string $successDetails = 'Ready',
    ): array {
        return [
            'label' => $label,
            'passed' => $passed,
            'required' => $required,
            'details' => $details,
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

    /** @return array{label: string, passed: bool, required: bool, details: string, success_details: string} */
    private function queueRetryWindowCheck(): array
    {
        $connection = (string) config('queue.default');
        $retryAfter = config("queue.connections.{$connection}.retry_after");
        $jobTimeout = max(60, (int) config('secretary.limits.job_timeout', 1200));
        $passed = $connection === 'sync' || (is_numeric($retryAfter) && (int) $retryAfter > $jobTimeout);

        return $this->check(
            'Queue retry window',
            $passed,
            false,
            "Set the [{$connection}] queue retry_after above Secretary's {$jobTimeout}-second job timeout.",
        );
    }

    /** @return array{label: string, passed: bool, required: bool, details: string, success_details: string} */
    private function relayCacheCheck(RelayConfiguration $relay): array
    {
        $store = $relay->cacheStore() ?: (string) config('cache.default');
        $driver = (string) config("cache.stores.{$store}.driver");

        return $this->check(
            'Relay replay cache',
            ! $relay->enabled() || ($store !== '' && $driver !== '' && $driver !== 'array'),
            $relay->enabled(),
            'Use a persistent shared cache store for single-use relay nonces; the array store cannot prevent replay across requests.',
            $relay->enabled() ? 'Ready' : 'Not configured.',
        );
    }
}
