<?php

namespace App\Providers;

use App\Models\Incident;
use App\Models\NewsItem;
use App\Models\Setting;
use App\Services\Announcer;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // Announce changes in the public Telegram group, whoever made them (website or bot).
        Incident::created(fn (Incident $i) => app(Announcer::class)->incidentCreated($i));
        Incident::updated(function (Incident $i) {
            if ($i->wasChanged('status')) {
                app(Announcer::class)->incidentStatusChanged($i);
            }
        });
        NewsItem::created(fn (NewsItem $n) => app(Announcer::class)->newsAdded($n));
        Setting::saved(function (Setting $s) {
            if ($s->key === 'alert_level' && $s->wasChanged('value')) {
                app(Announcer::class)->levelChanged();
            }
        });
    }
}
