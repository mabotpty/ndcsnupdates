<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use Illuminate\Console\Command;

class NewsPruneCommand extends Command
{
    protected $signature = 'news:prune';

    protected $description = 'Remove news posts older than 14 days';

    public function handle(): int
    {
        $count = 0;

        // One by one so each removal updates the site's "last updated" stamp like any other change.
        NewsItem::where('published_at', '<', now()->subDays(NewsItem::KEEP_DAYS))->each(function (NewsItem $n) use (&$count) {
            $n->delete();
            $count++;
        });

        $this->info("Removed {$count} news post".($count === 1 ? '' : 's').' older than '.NewsItem::KEEP_DAYS.' days.');

        return self::SUCCESS;
    }
}
