<?php

use Illuminate\Support\Facades\Schedule;

// Times are South African (config/app.php timezone). Needs one cron line on the server:
//   * * * * * cd <app> && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('incidents:auto-resolve')->everyTenMinutes()->withoutOverlapping();

// News only stays for 14 days.
Schedule::command('news:prune')->dailyAt('03:10')->withoutOverlapping();

// Start each day with a clean situation report: every road is removed at 03:00 (sections stay).
Schedule::command('report:clear-roads')->dailyAt('03:00')->withoutOverlapping();
