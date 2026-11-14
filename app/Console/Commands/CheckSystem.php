<?php

namespace App\Console\Commands;

use App\Support\SystemCheck;
use Illuminate\Console\Command;

class CheckSystem extends Command
{
    protected $signature = 'sk:check';

    protected $description = 'Check the system before other people use it (settings, upload limits, folders, backups)';

    public function handle(): int
    {
        $results = (new SystemCheck())->run();

        $this->table(['Check', 'Result', 'What it means'], array_map(fn ($r) => [$r['name'], $r['status'], wordwrap($r['detail'], 84, "\n")], $results));

        $fail = SystemCheck::failures($results);
        $warn = count(array_filter($results, fn ($r) => $r['status'] === SystemCheck::WARN));

        if ($fail) {
            $this->error("{$fail} problem(s) must be fixed, {$warn} reminder(s).");

            return self::FAILURE;
        }
        $this->info($warn ? "Nothing is broken. {$warn} reminder(s) above." : 'All good.');

        return self::SUCCESS;
    }
}
