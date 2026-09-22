<?php

namespace App\Console\Commands;

use App\Support\Retention;
use Illuminate\Console\Command;

class CleanupChannels extends Command
{
    protected $signature = 'channels:cleanup';

    protected $description = 'Apply every retention rule: inactive channels, expired uploads, abandoned identities, stale tombstones';

    public function handle(): int
    {
        // The same rules as the lazy hourly sweep that API traffic triggers.
        // The two used to carry different subsets of them.
        $removed = Retention::prune();

        foreach ($removed as $what => $count) {
            $this->line(sprintf('  %-12s %d', $what, $count));
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
