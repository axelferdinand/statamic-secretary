<?php

namespace AxelFerdinand\StatamicSecretary\Commands;

use AxelFerdinand\StatamicSecretary\Agent\AgentOrchestrator;
use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\OpenAI\OpenAIConfiguration;
use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Throwable;

final class DryRunCommand extends Command
{
    protected $signature = 'secretary:dry-run
        {instruction : The natural-language content request to inspect}
        {--user= : Statamic user ID or email used for native authorization}
        {--entry= : Optional entry ID used as “this page” context}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Run Secretary through its real tools without writing Statamic content';

    public function handle(ConversationService $conversations, AgentOrchestrator $agent): int
    {
        if (! app(OpenAIConfiguration::class)->configured()) {
            return $this->failCommand('OpenAI is not configured.');
        }

        $identity = trim((string) $this->option('user'));
        $user = $identity !== '' ? (User::find($identity) ?? User::findByEmail($identity)) : null;

        if (! $user || ! $user->can('use secretary')) {
            return $this->failCommand('Pass --user with a Statamic user that may use Secretary.');
        }

        $context = ['dry_run' => true];
        $entryId = trim((string) $this->option('entry'));

        if ($entryId !== '') {
            $entry = Entry::find($entryId);

            if (! $entry || ! $user->can('view', $entry)) {
                return $this->failCommand('The selected entry does not exist or is not visible to this user.');
            }

            $context['cp_context'] = [
                'resource_type' => 'entry',
                'resource_id' => $entry->id(),
                'collection' => $entry->collection()->handle(),
                'site' => $entry->locale(),
                'title' => (string) ($entry->get('title') ?: $entry->slug()),
                'uri' => $entry->uri(),
                'edit_url' => $entry->editUrl(),
            ];
        }

        try {
            $conversation = $conversations->start('cli', $user, context: $context);
            $message = $conversations->recordInbound(
                $conversation,
                (string) $this->argument('instruction'),
                $user,
                'cli',
                ['dry_run' => true],
            );
            $reply = $agent->respond($conversation, $message, $user, dryRun: true);
            $changes = $conversation->changeSets()->get()->map(fn ($change): array => [
                'id' => $change->id,
                'status' => $change->status,
                'operation' => $change->operation,
                'resource_type' => $change->resource_type,
                'resource_id' => $change->resource_id,
                'fields' => array_keys((array) $change->patch),
                'summary' => $change->summary,
            ])->values()->all();
        } catch (Throwable $exception) {
            report($exception);

            return $this->failCommand($exception->getMessage());
        }

        $result = [
            'ok' => true,
            'dry_run' => true,
            'conversation_id' => $conversation->id,
            'reply' => $reply->body,
            'usage' => (array) data_get($reply->metadata, 'usage', []),
            'changes' => $changes,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Dry run complete. No Statamic content was written.');
            $this->line($reply->body);

            if ($changes !== []) {
                $this->newLine();
                $this->table(
                    ['Change', 'Status', 'Resource', 'Fields'],
                    collect($changes)->map(fn (array $change): array => [
                        $change['summary'] ?: $change['id'],
                        $change['status'],
                        $change['resource_type'].':'.$change['resource_id'],
                        implode(', ', $change['fields']),
                    ])->all(),
                );
            }
        }

        return self::SUCCESS;
    }

    private function failCommand(string $message): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => false, 'error' => $message], JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
