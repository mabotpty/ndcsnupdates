<?php

use Illuminate\Support\Facades\Schedule;

// Times are South African (config/app.php timezone). Needs one cron line on the server:
//   * * * * * cd <app> && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('incidents:auto-resolve')->everyTenMinutes()->withoutOverlapping();
Schedule::command('report:clear-areas')->dailyAt('00:00')->withoutOverlapping();
