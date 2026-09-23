<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One copy of a scheduled sweep at a time, across every worker.
 *
 * ⚠️ `Schedule::job(...)->withoutOverlapping()` guards the DISPATCH, not the run:
 * for a queued job the scheduler's lock wraps a push of a few milliseconds and is
 * released long before a worker starts. What kept two copies of one sweep apart
 * until now was `supervisor-maintenance` running ONE process — a pile of the same
 * job left by an outage or a deploy drained one after another. At two processes
 * that pile runs two at a time, so the guard has to be on the job.
 *
 * ⚠️ `expireAfter()` IS THE LOAD-BEARING HALF. The middleware lock does not expire
 * on its own, so a worker killed mid-run (a deploy, an OOM, the timeout) would
 * hold it for ever and the sweep would silently never run again. The expiry is the
 * supervisor's 900-second timeout plus a minute, like the jobs that spell their
 * own `middleware()` out.
 *
 * `dontRelease()`: a duplicate of a sweep is dropped rather than retried — the
 * next scheduled run does the same walk, and with `tries: 1` a released copy
 * would only land in `failed_jobs`.
 *
 * The lock is keyed on the class. A job whose arguments name a different walk
 * (a leaderboard PERIOD) overrides `overlapKey()`, or two different walks would
 * drop each other; a job about one row does not use this trait at all.
 */
trait RunsAlone
{
    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->overlapKey()))
            ->expireAfter(900 + 60)
            ->dontRelease()];
    }

    protected function overlapKey(): string
    {
        return static::class;
    }
}
