<?php

namespace App\Console\Commands;

use App\Models\ReportEntry;
use Illuminate\Console\Command;

class ReportClearRoadsCommand extends Command
{
    protected $signature = 'report:clear-roads';

    protected $description = 'Remove every road from the situation report (runs nightly at 03:00); sections are kept and show "No reported issues"';

    public function handle(): int
    {
        $count = 0;

        // One by one so each removal updates the site's "last updated" stamp like any other change.
        ReportEntry::query()->each(function (ReportEntry $entry) use (&$count) {
            $entry->delete();
            $count++;
        });

        $this->info("Removed {$count} situation report road".($count === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
