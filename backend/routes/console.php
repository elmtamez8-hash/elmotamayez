<?php

use App\Modules\LiveSessions\Jobs\CloseStaleSessionsJob;
use App\Modules\Media\Jobs\PruneExpiredGrantsJob;
use App\Modules\Notifications\Jobs\PruneOldNotificationsJob;
use App\Modules\Payments\Jobs\ChargeUnbilledDeliveriesJob;
use App\Modules\Payments\Jobs\EvaluateCreditLimitsJob;
use App\Modules\Settlement\Jobs\CloseDueSettlementPeriodsJob;
use App\Modules\Settlement\Jobs\ReleasePendingUnitsJob;
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

// Waiting units become earnings the moment their recording lands. Every fifteen
// minutes rather than hourly: this is a teacher watching an hour they taught sit
// uncounted, and the cost of asking is one indexed query over the backlog. A
// sweep rather than a listener because "the recording failed" is the ABSENCE of
// an event — nothing fires when a file never arrives, and that branch has to be
// noticed too.
Schedule::job(new ReleasePendingUnitsJob)->everyFifteenMinutes();

// Windows whose days have run out get closed and their totals frozen. Daily and
// at :10 past four — clear of both bulk deletes at 03:30/03:45 and of the hourly
// session sweep at :20, because this one holds a transaction per teacher and has
// no business queueing behind a mass delete's locks. Daily rather than hourly:
// the boundary it acts on is a DATE, so running it twelve more times a day would
// find nothing eleven of them.
Schedule::job(new CloseDueSettlementPeriodsJob)->dailyAt('04:10');

// Delivered sessions nobody charged. Every fifteen minutes, at :05 past the
// quarter so it trails ReleasePendingUnitsJob rather than racing it — both walk
// recently delivered sessions, and staggering keeps two sweeps off the same rows
// in the same second. Frequent for the same reason: what it repairs is a student
// who keeps booking on credits they have already spent, and every quarter hour of
// delay is another seat taken on money that was never deducted.
Schedule::job(new ChargeUnbilledDeliveriesJob)->cron('5,20,35,50 * * * *');

// Balances that have owed for longer than the platform allows lose their ceiling
// (FR-040). Daily, because the boundary it acts on is measured in DAYS — running
// it hourly would ask the same question twenty-three more times for one answer —
// and at 04:25, clear of every sweep above so it never queues behind their locks.
// A sweep rather than a listener: falling behind is the absence of an event, and
// nothing fires on the fourteenth day of owing.
Schedule::job(new EvaluateCreditLimitsJob)->dailyAt('04:25');
