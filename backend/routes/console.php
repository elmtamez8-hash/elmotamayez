<?php

use App\Modules\LiveSessions\Jobs\CloseStaleSessionsJob;
use App\Modules\Media\Jobs\PruneExpiredGrantsJob;
use App\Modules\Notifications\Jobs\PruneOldNotificationsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention housekeeping (FR-017). Nightly and off-peak: it is a bulk delete, and
// nothing depends on it having run by any particular hour.
Schedule::job(new PruneOldNotificationsJob)->dailyAt('03:30');

// Playback grants outlive their usefulness by a week so a support question about
// last Tuesday can still be answered. Staggered off the notification prune: two
// bulk deletes on the same minute is one lock contention nobody planned for.
Schedule::job(new PruneExpiredGrantsJob)->dailyAt('03:45');

// The safety net under each session's own delayed close. Hourly rather than
// nightly because what it repairs is a register nobody can read and a report no
// guardian received — and at :20, off both bulk deletes above, since a sweep
// that closes sessions has no business waiting behind a mass delete's locks.
Schedule::job(new CloseStaleSessionsJob)->hourlyAt(20);
