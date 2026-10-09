<?php

namespace Database\Seeders;

use App\Models\AlertLevel;
use App\Models\Incident;
use App\Models\NewsItem;
use App\Models\ReportEntry;
use App\Models\ReportGroup;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seeds the content that was on the original static page, so the new site
 * launches looking exactly like the old one. Safe to re-run: it only fills
 * tables that are still empty.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! AlertLevel::query()->exists()) {
            foreach ([
                [0, 'Green - Level 0', 'green', 'All is normal and accessible.'],
                [1, 'Yellow - Level 1', 'yellow', 'Active monitoring, no issues, but maintain awareness'],
                [2, 'Yellow - Level 2', 'yellow', 'Minor issues. Non-violent protests and marches. Maintain awareness.'],
                [3, 'Orange - Level 3', 'orange', 'Significant issues. Violent flare ups. Avoid travel where possible.'],
                [4, 'Red - Level 4', 'red', 'Full lock down. Remain indoors.'],
            ] as [$level, $name, $colour, $description]) {
                AlertLevel::create(compact('level', 'name', 'colour', 'description'));
            }
        }

        if (Setting::find('alert_level') === null) {
            Setting::put('alert_level', '1');
            Setting::put('resolved_hours', '48');
        }

        if (! ReportGroup::query()->exists()) {
            $transport = ReportGroup::create(['title' => 'Transport Routes', 'sort' => 1]);
            $areas = ReportGroup::create(['title' => 'Malls, Shopping Centres, Residential Areas', 'sort' => 2]);

            ReportEntry::create(['report_group_id' => $transport->id, 'area' => 'Northern Area', 'status' => 'ok', 'lines' => 'No reported issues', 'sort' => 1]);
            ReportEntry::create(['report_group_id' => $transport->id, 'area' => 'Durban City/Surrounds', 'status' => 'ok', 'lines' => 'No reported issues', 'sort' => 2]);
            ReportEntry::create(['report_group_id' => $areas->id, 'area' => null, 'status' => 'ok', 'lines' => 'No reported issues', 'sort' => 1]);
        }

        if (! Incident::query()->exists()) {
            Incident::create([
                'title' => 'March and March Update',
                'body' => 'March and March Leader Jacinta Ngobese Zuma says the group will demonstrate weekly, until all undocumented foreign nationals have left the country.',
                'status' => 'warning',
                'published_at' => '2026-07-01 09:00:00',
            ]);
        }

        if (! NewsItem::query()->exists()) {
            $items = json_decode(file_get_contents(__DIR__.'/data/news.json'), true);
            foreach ($items as $item) {
                NewsItem::create($item);
            }
        }
    }
}
