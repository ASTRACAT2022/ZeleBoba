<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('subscriptions:process')->everyMinute()->withoutOverlapping();

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled domain tasks are added here as each legacy lifecycle service is
// migrated. Keeping the scheduler process present from day one avoids a
// second infrastructure cutover later.
Schedule::command('queue:prune-failed --hours=168')->daily();
