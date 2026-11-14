<?php

namespace App\Console\Commands;

use App\Support\MessageFiles;
use Illuminate\Console\Command;

class PurgePendingMessageFiles extends Command
{
    protected $signature = 'messages:purge-pending';

    protected $description = 'Remove chat files that were uploaded but never sent';

    public function handle(): int
    {
        $count = MessageFiles::purgePending();
        $this->info("Removed {$count} unsent file(s).");

        return self::SUCCESS;
    }
}
