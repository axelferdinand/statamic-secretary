<?php

namespace AxelFerdinand\StatamicSecretary\Diagnostics;

use AxelFerdinand\StatamicSecretary\Content\SafeDrafting;
use AxelFerdinand\StatamicSecretary\Database\SecretaryDatabase;
use AxelFerdinand\StatamicSecretary\Developer\ToolRegistry;
use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\OpenAI\OpenAIConfiguration;
use AxelFerdinand\StatamicSecretary\OpenAI\OpenAIHealthCheck;
use AxelFerdinand\StatamicSecretary\Relay\RelayConfiguration;
use Statamic\Assets\AssetUploader;
use Statamic\Facades\AssetContainer;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory;
use Throwable;

final class DoctorReport
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly SafeDrafting $safeDrafting,
        private readonly SecretaryDatabase $database,
        private readonly OpenAIHealthCheck $openAIHealth,
    ) {}

    /** @return array<int, array{key: string, label: string, passed: bool, required: bool, details: string, success_details: string}> */
    public function checks(EmailConfiguration $email, RelayConfiguration $relay, bool $probeOpenAI = false): array
    {
        $root = (string) (config('secretary.content.root') ?: base_path('content'));
        $emailEnabled = $email->enabled();
        $postmarkSetupPending = $email->tokenConfigured() && ! $email->connected() && blank(config('secretary.email.enabled'));
        $openAI = app(OpenAIConfiguration::class);
        $openAIHealth = $probeOpenAI && $openAI->configured()
            ? $this->openAIHealth->run()
            : $openAI->health();

        return [
            $this->check('openai_key', 'OpenAI API key', $openAI->configured(), true, 'Add the key in Secretary or set OPENAI_API_KEY.'),
            $this->check('openai_model', 'OpenAI model', filled(config('secretary.openai.model')), true, 'Set SECRETARY_OPENAI_MODEL.'),
            $this->openAIHealthCheck($openAI, $openAIHealth),
            $this->check('content_root', 'Content root', is_dir($root) && is_writable($root), true, 'The configured content directory must exist and be writable.'),
            $this->check(
                'database',
                'Secretary storage',
                $this->database->ready(),
                true,
                'Secretary could not initialize its private storage. Make sure Laravel\'s storage directory is writable, then run the checks again.',
                'Private storage is ready.',
            ),
            $this->revisionCheck(),
            $this->backgroundProcessingCheck(),
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
            $this->assetCheck(),
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

    /**
     * @param  array{passed: bool, details: string, checked_at: string}|null  $health
     * @return array{key: string, label: string, passed: bool, required: bool, details: string, success_details: string}
     */
    private function openAIHealthCheck(OpenAIConfiguration $configuration, ?array $health): array
    {
        if (! $configuration->configured()) {
            return $this->check(
                'openai_access',
                'OpenAI access and credits',
                false,
                true,
                'Connect an OpenAI API key, then run the checks again.',
            );
        }

        if ($health === null) {
            return $this->check(
                'openai_access',
                'OpenAI access and credits',
                false,
                false,
                'Not tested yet. Run the checks once to verify the selected model and available credits.',
            );
        }

        return $this->check(
            'openai_access',
            'OpenAI access and credits',
            $health['passed'],
            true,
            $health['details'],
            $health['details'],
        );
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
            'Job retry protection',
            $passed,
            false,
            "Set the [{$connection}] queue retry_after above Secretary's {$jobTimeout}-second job timeout.",
        );
    }

    private function revisionCheck(): array
    {
        $status = $this->safeDrafting->status();

        return $this->check(
            'revisions',
            'Safe drafts',
            $status['ready'],
            true,
            $status['details'],
            $status['success_details'],
        );
    }

    private function backgroundProcessingCheck(): array
    {
        $connection = (string) config('queue.default');
        $details = $connection === 'sync'
            ? 'Built-in processing is active. No queue worker is required.'
            : "Uses your site's [{$connection}] queue connection.";

        return $this->check(
            'queue',
            'Background processing',
            true,
            false,
            '',
            $details,
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

    private function assetCheck(): array
    {
        $enabled = (bool) config('secretary.assets.enabled', true);

        if (! $enabled) {
            return $this->check('assets', 'Asset access', true, false, '', 'Disabled.');
        }

        $configured = array_values(array_filter(array_map(
            static fn (mixed $handle): string => trim((string) $handle),
            (array) config('secretary.assets.containers', []),
        )));
        $available = AssetContainer::all()
            ->filter(fn ($container): bool => $configured === [] || in_array($container->handle(), $configured, true))
            ->values();
        $attachmentContainer = trim((string) config('secretary.assets.attachment_container'));
        $folder = trim((string) config('secretary.assets.attachment_folder', 'secretary-inbox'), " \t\n\r\0\x0B/\\");
        $folderIsSafe = $folder === AssetUploader::getSafePath($folder) && ! str_contains($folder, '..');
        $configuredExist = collect($configured)->every(
            fn (string $handle): bool => AssetContainer::find($handle) !== null,
        );
        $attachmentContainerIsValid = $attachmentContainer !== ''
            ? AssetContainer::find($attachmentContainer) !== null
                && ($configured === [] || in_array($attachmentContainer, $configured, true))
            : $available->count() === 1;
        $passed = $folderIsSafe && $configuredExist && $attachmentContainerIsValid;

        return $this->check(
            'assets',
            'Asset access',
            $passed,
            false,
            'Configure one valid SECRETARY_ATTACHMENT_CONTAINER (and optional SECRETARY_ASSET_CONTAINERS) plus a safe relative attachment folder.',
            'Ready for existing assets and authenticated image attachments.',
        );
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
