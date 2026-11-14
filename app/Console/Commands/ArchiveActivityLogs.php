<?php

namespace App\Console\Commands;

use App\Support\ActivityArchive;
use Illuminate\Console\Command;

/** Saves the finished months of the activity log as files (see App\Support\ActivityArchive). */
class ArchiveActivityLogs extends Command
{
    protected $signature = 'activity:archive
                            {--month= : Save only this month, as YYYY-MM (for example 2026-09), even if it is not finished yet}
                            {--rebuild : Write every finished month and year file again from the database}';

    protected $description = 'Save each finished month of the activity log as a file (storage/app/private/activity-logs/YEAR/)';

    public function handle(): int
    {
        $month = $this->option('month');

        if ($month !== null && $month !== '') {
            if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', (string) $month, $m)) {
                $this->error('Use --month=YYYY-MM, for example --month=2026-09.');

                return self::FAILURE;
            }

            $lines = ActivityArchive::writeMonth((int) $m[1], (int) $m[2]);
            ActivityArchive::writeYear((int) $m[1]);

            $this->info(ActivityArchive::labelFor((int) $m[1], $m[2]) . ": {$lines} line(s) saved, and the whole-year file of {$m[1]} updated.");

            return self::SUCCESS;
        }

        $written = ActivityArchive::sync((bool) $this->option('rebuild'));

        if ($written['months'] === 0 && $written['years'] === 0) {
            $this->info('Everything is already saved. Nothing to do.');
        } else {
            $this->info("Saved {$written['months']} month file(s) and {$written['years']} whole-year file(s).");
        }

        return self::SUCCESS;
    }
}
