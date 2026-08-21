<?php

use App\Modules\Assessments\Jobs\MarkMissedSubmissionsJob;
use App\Modules\Assessments\Jobs\RollUpQuestionStatsJob;
use App\Modules\Compliance\Jobs\PruneExpiredExportsJob;
use App\Modules\Compliance\Jobs\RetryStalledDataRequestsJob;
use App\Modules\Compliance\Jobs\RunRetentionSweepJob;
use App\Modules\Gamification\Jobs\CloseLeaderboardWeekJob;
use App\Modules\Gamification\Jobs\PruneOldLeaderboardsJob;
use App\Modules\Gamification\Jobs\ReconcileGamificationJob;
use App\Modules\Gamification\Jobs\RollUpLeaderboardsJob;
use App\Modules\Identity\Jobs\TransferDataOwnershipJob;
use App\Modules\LiveSessions\Jobs\CloseStaleSessionsJob;
use App\Modules\LiveSessions\Jobs\RetryPendingRecordingsJob;
use App\Modules\Media\Jobs\PruneExpiredGrantsJob;
use App\Modules\Media\Jobs\ReconcileAssetStatus;
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

// Playback grants outlive their usefulness by a week so a support question about
// last Tuesday can still be answered. Staggered off the retention sweep at 03:30
// — which is what now holds the slot the notification prune used to — because two
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

// Recordings stuck on "pending" get their ingest re-sent. Same fifteen minutes as
// the sweep directly above, which READS the result: slower here would mean a unit
// released an hour after its recording landed, and faster buys nothing.
//
// ⚠️ It exists because nothing re-sent the job at all. `giveUpOrRetry` wrote
// 'pending' and returned, and the only dispatcher was SessionCompleted — one
// event, one attempt. The cost was not a stuck badge: PackageCompletion holds a
// teacher's fee for any session whose recording is neither published nor failed.
Schedule::job(new RetryPendingRecordingsJob, 'maintenance')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

/*
| Assets the provider is still working on (019).
|
| ⚠️ THIS JOB EXISTED SINCE 004 AND WAS NEVER SCHEDULED, which was invisible while
| the only provider settled an asset inside the request that uploaded it — nothing
| ever reached `Processing` and stayed. A provider that transcodes on its own clock
| makes `Processing` a state something has to leave, and until this line nothing
| asked: a teacher's own upload would sit «قيد التجهيز» for ever.
|
| Every five minutes rather than fifteen, and that is the one number here with a
| person behind it: this is a teacher watching the video they just uploaded, not a
| background reconciliation. Its own queue, like every other sweep that walks the
| platform, so it never sits behind a mass delete's locks. `withoutOverlapping()`
| because a slow provider is exactly when two copies would walk the same rows.
*/
Schedule::job(new ReconcileAssetStatus, 'maintenance')
    ->everyFiveMinutes()
    ->withoutOverlapping();

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

/*
|--------------------------------------------------------------------------
| Gamification (spec 009)
|--------------------------------------------------------------------------
|
| ⚠️ THREE OF THESE FOUR CARRY `->timezone('Asia/Qatar')` EXPLICITLY, and it is
| not decoration. The scheduler runs on `config/app.timezone`, which is UTC and
| stays UTC — stored timestamps are not being moved. What these three answer is a
| question about a QATARI CALENDAR EDGE: "Sunday dawn" and "the day is over".
| Without the timezone, the week seals three hours after the student's own week
| has already rolled over, and the daily boundary the cap is computed on and the
| boundary the board is built on drift apart.
|
| ReconcileGamificationJob deliberately does NOT carry one: it is bounded by
| `created_at >= :since`, so what hour it runs at means nothing.
*/

// The current week's boards, rebuilt from the ledger. Hourly at :35, clear of
// the payments reconciliation at :50 and the session sweep at :20 — it is a
// grouped scan over the fastest-growing table in the module.
Schedule::job(new RollUpLeaderboardsJob, 'maintenance')
    ->hourlyAt(35)
    ->timezone('Asia/Qatar')
    ->withoutOverlapping();

// The week that ended, sealed. 00:10 Sunday Doha: ten minutes past the boundary,
// so every award of the closing week has certainly landed.
Schedule::job(new CloseLeaderboardWeekJob, 'maintenance')
    ->weeklyOn(0, '00:10')
    ->timezone('Asia/Qatar')
    ->withoutOverlapping();

// Retention on the derived table (FR-026). 03:15, ahead of the notification
// prune at 03:30 and far from every rollup — two bulk deletes on one minute is
// a lock contention nobody planned for.
Schedule::job(new PruneOldLeaderboardsJob, 'maintenance')
    ->dailyAt('03:15')
    ->timezone('Asia/Qatar')
    ->withoutOverlapping();

// Does the aggregate still equal the sum of its entries? Bounded by movement,
// never a full sweep. 04:20, between the billing reconciliations.
Schedule::job(new ReconcileGamificationJob, 'maintenance')
    ->dailyAt('04:20')
    ->withoutOverlapping();

/*
| Spec 013 — the data-rights sweeps.
|
| ⚠️ THE STALLED SWEEP IS THE ONLY RETRY `FulfilDataRequestJob` HAS. That job runs
| with `tries: 1` because a retried job would re-enter a request already marked
| `processing` and be refused by its own claim — so a worker killed mid-export (a
| deploy, an OOM, a restart) leaves the request `processing` for ever, `due_at`
| passes, the legal deadline is missed and nothing is broken enough to log. The
| `recording_status = 'ingesting'` family exactly.
|
| Every ten minutes, which is a cadence nothing else in this file uses: the
| five-, fifteen- and hourly slots are taken, and a sweep sharing a minute with a
| bulk delete waits behind its locks for the one thing that is measured in days.
*/
Schedule::job(new RetryStalledDataRequestsJob, 'maintenance')
    ->everyTenMinutes()
    ->withoutOverlapping();

/*
| The archives whose links have expired (FR-018).
|
| 05:35 — clear of the 03:30/03:45 bulk deletes, of the 04:xx billing closes and
| of the 05:15 rollup. This one touches the DISK rather than a hot table, so it is
| kept away from the window where a slow filesystem would hold a worker that
| something else is queued behind.
*/
Schedule::job(new PruneExpiredExportsJob, 'maintenance')
    ->dailyAt('05:35')
    ->timezone('Asia/Qatar')
    ->withoutOverlapping();

/*
| Everything whose declared retention has run out (FR-028 … FR-031).
|
| 03:30 — the slot `PruneOldNotificationsJob` used to hold, and this job is what
| replaced it: that one read `config('notifications.retention_days')`, a SECOND
| owner of a duration `data_categories` already held, so what an operator
| shortened from the panel was not what deleted. Nightly and off-peak because it
| is a bulk delete across seven tables and nothing depends on it having finished
| by any particular hour.
|
| ⚠️ NO `->withoutOverlapping()` HERE, AND THAT IS THE ONE DEPARTURE FROM EVERY
| OTHER LINE IN THIS FILE. The scheduler's lock wraps `dispatchToQueue()` — for a
| queued job, a few milliseconds around the push, released long before the worker
| starts — so a sweep still walking at midnight would happily run beside tonight's.
| The guard is job middleware with an explicit `expireAfter()`, and the reason for
| each half is written on the job.
*/
Schedule::job(new RunRetentionSweepJob, 'compliance')->dailyAt('03:30');

/*
| A student who turned eighteen owns their own data (FR-009).
|
| ⚠️ `->timezone('Asia/Qatar')` BECAUSE A BIRTHDAY IS A CALENDAR QUESTION, not a
| moment. On UTC the sweep runs at 09:05 Doha, so somebody whose eighteenth
| birthday is today is told about it after most of that day has gone — and the
| boundary the job compares against and the boundary the student lives in are two
| different days for three hours out of every twenty-four.
|
| 06:25 Doha, which the crontab renders as 03:25 UTC — BEFORE the retention sweep
| at 03:30 and before every 04:xx close, and stated that way round because a
| comment claiming "after everything above" would be read against a line that says
| the opposite. It does not need to be after any of them: it walks one indexed
| predicate over `student_profiles` and touches no table any of those write. It is
| kept off :05/:20/:35/:50 UTC, where the quarter-hourly billing sweeps sit.
*/
Schedule::job(new TransferDataOwnershipJob, 'compliance')
    ->dailyAt('06:25')
    ->timezone('Asia/Qatar');
