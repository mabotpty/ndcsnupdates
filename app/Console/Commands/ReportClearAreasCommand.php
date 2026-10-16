<?php

namespace App\Console\Commands;

use App\Models\ReportEntry;
use Illuminate\Console\Command;

class ReportClearAreasCommand extends Command
{
    protected $signature = 'report:clear-areas';

    protected $description = 'Clear the "Area" field on every situation report entry (runs nightly at midnight)';

    public function handle(): int
    {
        $entries = ReportEntry::whereNotNull('area')->get();

        foreach ($entries as $entry) {
            $entry->update(['area' => null]);
        }

        $this->info("Cleared the area on {$entries->count()} situation report entr".($entries->count() === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
