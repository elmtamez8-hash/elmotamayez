<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/*
| Every scheduled job guards its own RUN, not only its dispatch.
|
| `Schedule::job(...)->withoutOverlapping()` wraps a push of a few milliseconds,
| so it never kept two copies of one sweep apart; `supervisor-maintenance`
| running a single process did, by accident. At two processes a pile of one sweep
| (an outage, a deploy) runs two at a time — so each scheduled job must carry a
| `WithoutOverlapping` of its own, and with an EXPIRY, or a worker killed mid-run
| holds the lock for ever and the sweep never runs again.
|
| A job may be exempt only by being named below with the reason.
*/

/** Scheduled jobs deliberately without their own overlap guard, and why. */
const SWEEPS_EXEMPT_FROM_RUNS_ALONE = [
    // Read-only apart from one log line: two copies log the same drift twice.
    'App\Modules\Gamification\Jobs\ReconcileGamificationJob',
    // Idempotent by key: an upsert on (date, metric_key, workspace_id,
    // region_id) — two copies write the same numbers. Its `$day` argument is
    // the backfill path, which a class-keyed lock would silently drop.
    'App\Modules\Analytics\Jobs\RollUpPlatformMetricsJob',
    // On `default` (supervisor-1), never on maintenance; out of this guard's scope.
    'App\Modules\Settlement\Jobs\ReleasePendingUnitsJob',
];

it('gives every scheduled job an expiring overlap guard of its own', function (): void {
    $jobs = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => $event instanceof CallbackEvent)
        ->map(fn (CallbackEvent $event): string => (string) $event->description)
        // `Schedule::job()` names its event after the job class.
        ->filter(fn (string $name): bool => class_exists($name) && is_subclass_of($name, ShouldQueue::class))
        ->unique()
        ->values();

    // Guard against a vacuous pass: the schedule carries twenty-odd sweeps.
    expect($jobs->count())->toBeGreaterThan(20);

    $unguarded = $jobs
        ->reject(fn (string $class): bool => in_array($class, SWEEPS_EXEMPT_FROM_RUNS_ALONE, true))
        ->reject(function (string $class): bool {
            $job = app()->make($class);

            if (! method_exists($job, 'middleware')) {
                return false;
            }

            foreach ($job->middleware() as $middleware) {
                if ($middleware instanceof WithoutOverlapping && $middleware->expiresAfter > 0) {
                    return true;
                }
            }

            return false;
        })
        ->values()
        ->all();

    expect($unguarded)->toBe([]);
});
