<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Models\Setting;
use Illuminate\Console\Command;

class IncidentsAutoResolveCommand extends Command
{
    protected $signature = 'incidents:auto-resolve';

    protected $description = 'Quietly resolve warnings/monitoring items that have been open too long (default 12 hours)';

    public function handle(): int
    {
        // Weather warnings expire on their own "to" time (+1 hour), not on the age rule below.
        $expired = Incident::weather()->where('status', '!=', 'resolved')
            ->whereNotNull('weather_to')
            ->where('weather_to', '<=', now()->subHour())
            ->get();

        foreach ($expired as $warning) {
            // announce stays false: resolved quietly, nothing goes to the Telegram group.
            $warning->update(['status' => 'resolved']);
        }
        if ($expired->isNotEmpty()) {
            $this->info("Quietly resolved {$expired->count()} expired weather warning(s).");
        }

        $hours = (int) Setting::get('auto_resolve_hours', '12');
        if ($hours <= 0) {
            return self::SUCCESS; // age rule switched off
        }

        // Age counts from when the item was posted to the site, so a backdated
        // "occurred at" time doesn't make a fresh item vanish immediately.
        $stale = Incident::where('type', '!=', 'weather')
            ->whereIn('status', ['warning', 'monitoring'])
            ->where('created_at', '<=', now()->subHours($hours))
            ->get();

        foreach ($stale as $incident) {
            $incident->update(['status' => 'resolved']);
        }

        if ($stale->isNotEmpty()) {
            $this->info("Quietly resolved {$stale->count()} item(s) open for {$hours}+ hours.");
        }

        return self::SUCCESS;
    }
}
