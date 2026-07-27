<?php

namespace AxelFerdinand\StatamicSecretary\Commands;

use AxelFerdinand\StatamicSecretary\Models\Conversation;
use Illuminate\Console\Command;

final class PruneCommand extends Command
{
    protected $signature = 'secretary:prune
        {--days= : Delete conversations whose last update is older than this many days}
        {--force : Delete without an interactive confirmation}';

    protected $description = 'Delete old Secretary conversations, messages, and change-set audit data';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('secretary.retention_days', 90);

        if (filter_var($days, FILTER_VALIDATE_INT) === false || (int) $days < 1) {
            $this->error('The retention period must be a whole number of at least one day.');

            return self::INVALID;
        }

        $query = Conversation::query()->where('updated_at', '<', now()->subDays((int) $days));
        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No Secretary conversations matched the retention window.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$count} old Secretary conversation(s) and their messages/change sets?")) {
            $this->warn('No data was deleted.');

            return self::SUCCESS;
        }

        $query->get()->each->delete();
        $this->info("Deleted {$count} old Secretary conversation(s).");

        return self::SUCCESS;
    }
}
