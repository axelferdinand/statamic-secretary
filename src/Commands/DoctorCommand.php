<?php

namespace AxelFerdinand\StatamicSecretary\Commands;

use AxelFerdinand\StatamicSecretary\Diagnostics\DoctorReport;
use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Relay\RelayConfiguration;
use Illuminate\Console\Command;

final class DoctorCommand extends Command
{
    protected $signature = 'secretary:doctor {--json : Emit machine-readable JSON}';

    protected $description = 'Check Secretary configuration without exposing secrets';

    public function handle(DoctorReport $report, EmailConfiguration $email, RelayConfiguration $relay): int
    {
        $rows = $report->checks($email, $relay);
        $failed = collect($rows)->contains(fn (array $row): bool => $row['required'] && ! $row['passed']);

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => ! $failed,
                'checks' => $rows,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['Check', 'Status', 'Details'], collect($rows)->map(fn (array $row): array => [
            $row['label'],
            $row['passed'] ? '<info>OK</info>' : ($row['required'] ? '<error>FAIL</error>' : '<comment>WARN</comment>'),
            $row['passed'] ? $row['success_details'] : $row['details'],
        ])->all());

        if ($failed) {
            $this->newLine();
            $this->error('Secretary has blocking configuration problems.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Secretary is ready. Warnings describe optional or production-specific hardening.');

        return self::SUCCESS;
    }
}
