<?php

use App\Modules\Assessments\Jobs\MarkMissedSubmissionsJob;
use App\Modules\Assessments\Jobs\RollUpQuestionStatsJob;
use App\Modules\LiveSessions\Jobs\CloseStaleSessionsJob;
use App\Modules\Media\Jobs\PruneExpiredGrantsJob;
use App\Modules\Notifications\Jobs\PruneOldNotificationsJob;
use App\Modules\Payments\Jobs\ChargeUnbilledDeliveriesJob;
use App\Modules\Payments\Jobs\EvaluateCreditLimitsJob;
use App\Modules\Payments\Jobs\ExpireCreditLotsJob;
use App\Modules\Payments\Jobs\NotifyDormantBalancesJob;
use App\Modules\Payments\Jobs\ReconcileCreditBalancesJob;
use App\Modules\Payments\Jobs\ReconcilePaymentsJob;
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

/*
| ⚠️ EVERY BILLING SWEEP CARRIES `withoutOverlapping()` AND ITS OWN QUEUE.
|
| Overlap first: ChargeUnbilledDeliveriesJob runs every fifteen minutes, and one
| run that takes longer than its interval used to start a second copy over the
| same rows. The CHARGES stay safe — the ledger's unique index is what makes a
| replay harmless — but both copies re-walk the whole scan and both re-fetch a
| workspace per row, so the backlog that caused the overrun feeds itself. And a
| reconciliation overlapping itself writes two `credit_reconciliation_runs` rows
| for one night with different totals, after which "the last run" is a question
| with two answers.
|
| The queue second: none of these named one, so five sweeps that walk the whole
| platform shared the default queue with ChargeSeatsOnDelivery and every
| notification dispatch. A student's credits would be deducted behind a
| reconciliation — latency-sensitive work queued behind the least urgent work on
| the system, once a night, at exactly the hour nobody is watching.
*/

// Delivered sessions nobody charged. Every fifteen minutes, at :05 past the
// quarter so it trails ReleasePendingUnitsJob rather than racing it — both walk
// recently delivered sessions, and staggering keeps two sweeps off the same rows
// in the same second. Frequent for the same reason: what it repairs is a student
// who keeps booking on credits they have already spent, and every quarter hour of
// delay is another seat taken on money that was never deducted.
Schedule::job(new ChargeUnbilledDeliveriesJob, 'maintenance')
    ->cron('5,20,35,50 * * * *')
    ->withoutOverlapping();

// Balances that have owed for longer than the platform allows lose their ceiling
// (FR-040). Daily, because the boundary it acts on is measured in DAYS — running
// it hourly would ask the same question twenty-three more times for one answer —
// and at 04:25, clear of every sweep above so it never queues behind their locks.
// A sweep rather than a listener: falling behind is the absence of an event, and
// nothing fires on the fourteenth day of owing.
Schedule::job(new EvaluateCreditLimitsJob, 'maintenance')
    ->dailyAt('04:25')
    ->withoutOverlapping();

// Lots whose validity has run out. Before the reconciliation below, deliberately:
// an expiry moves a balance, and a sweep that checked the books first would
// report every lot it was about to write off as a discrepancy for one night.
// Switched off in practice — `validity_days` defaults to null, so this finds
// nothing until an operator sets one (Q-5).
Schedule::job(new ExpireCreditLotsJob, 'maintenance')
    ->dailyAt('04:35')
    ->withoutOverlapping();

// Does the ledger still add up? Nightly, after every sweep that moves a balance,
// so what it reads is the settled state rather than a snapshot mid-write. Three
// GROUP BYs over the fastest-growing tables of this phase — which is exactly why
// it is a job and not the GET that reads its results.
Schedule::job(new ReconcileCreditBalancesJob, 'maintenance')
    ->dailyAt('04:45')
    ->withoutOverlapping();

// Credits nobody came back for (Q-8). Weekly, not nightly: the boundary is
// measured in MONTHS, and the notice is a courtesy — asking the same question
// every night for a year is how a courtesy becomes the reason someone turns
// notifications off. Sunday, clear of the nightly run above.
Schedule::job(new NotifyDormantBalancesJob, 'maintenance')
    ->weeklyOn(0, '05:00')
    ->withoutOverlapping();

/*
| The payment that succeeded and never told us (US2 · SC-004).
|
| Hourly, not nightly, and that is the one number here with a person behind it:
| what this repairs is a student who paid and is still blocked, so the ceiling on
| how long they stay blocked IS the interval. A nightly sweep would make "paid at
| 09:00" mean "reachable at 04:00 tomorrow".
|
| At :50, clear of the quarter-hour billing sweeps at :05/:20/:35 and of the
| hourly session sweep at :20 — this one calls out to every provider and then
| joins the two tables that grow with every sale, and has no business queueing
| behind a mass delete's locks.
|
| ⚠️ `withoutOverlapping()` HERE AND NOT AS JOB MIDDLEWARE: the scheduler's lock
| expires on its own after 1440 minutes, the middleware's does not expire at all.
| A worker killed at its timeout would leave a permanent lock and the sweep would
| never run again, silently — the worst failure available to the thing whose
| entire job is noticing silence.
*/
Schedule::job(new ReconcilePaymentsJob, 'maintenance')
    ->hourlyAt(50)
    ->withoutOverlapping();

// Which questions students get wrong, recomputed (spec 008 · FR-014). Nightly
// at 05:15: clear of every sweep above and of the Sunday dormancy notice at
// 05:00, because this one holds a grouped scan per workspace over the two
// fastest-growing tables in the product. Daily rather than hourly — the screen
// it feeds is a teacher deciding whether a question is broken, and that decision
// does not change between breakfast and lunch.
Schedule::job(new RollUpQuestionStatsJob, 'maintenance')
    ->dailyAt('05:15')
    ->withoutOverlapping();

/*
| The deadline that has passed, written down (FR-051).
|
| 04:55, clear of the credit reconciliation at 04:45 and of the item-analysis
| rollup at 05:15 — this one walks every workspace's published assignments and
| has no business sharing a minute with a job that holds a transaction per
| teacher. Its own queue for the same reason every billing sweep has one.
|
| Daily, and never more often: the state it writes is "the deadline is gone",
| which becomes true once per assignment and never becomes false again. What it
| feeds is US7's unlock gate, which should find the night's verdicts already in
| place when the first student opens the app in the morning.
*/
Schedule::job(new MarkMissedSubmissionsJob, 'maintenance')
    ->dailyAt('04:55')
    ->withoutOverlapping();
