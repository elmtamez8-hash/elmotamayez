<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Jobs;

use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Compliance\Models\RetentionSweepRun;
use App\Modules\Compliance\Support\PersonalDataRegistry;
use App\Shared\Support\ExpiryBehaviour;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Everything whose declared retention has run out (spec 013 · FR-028 … FR-031).
 *
 * ⚠️ IT ASKS NOBODY. `ExecuteDataErasure` acts on ONE PERSON who requested it;
 * this acts on EVERY row older than a category's retention, for everybody, with
 * no request behind it. That is why `PersonalDataOwner` needs a fifth function at
 * all — `erase()` takes a subject and there is none here.
 *
 * ⚠️ AND A RUN ROW IS WRITTEN EVERY TIME, INCLUDING THE NIGHTS THAT FIND NOTHING.
 * A log that only records work done cannot distinguish "there was nothing to do"
 * from "the sweep has not run since March", which is the single question anybody
 * opens this table to answer.
 */
class RunRetentionSweepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** One statement's worth of rows. */
    private const BATCH = 500;

    /**
     * ⚠️ A CEILING PER CATEGORY, so one enormous table cannot starve the twelve
     * behind it. What is left is picked up tomorrow — the walk is resumable by
     * construction, because every predicate is "older than N days" and nothing
     * about that becomes false by being interrupted.
     */
    private const MAX_BATCHES_PER_CATEGORY = 200;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue('compliance');
    }

    /**
     * ⚠️ JOB MIDDLEWARE WITH AN EXPLICIT `expireAfter()`, AND BOTH HALVES OF THAT
     * ARE LOAD-BEARING — this is the ONE sweep in this codebase that does not use
     * the `Schedule::job(...)->withoutOverlapping()` idiom every other line in
     * `routes/console.php` uses, so the departure is written down here.
     *
     *  · The scheduler's lock wraps `dispatchToQueue()`. For a QUEUED job that is
     *    a few milliseconds around the push — taken and released long before the
     *    worker starts — so last night's sweep still walking when tonight's
     *    begins runs two copies over the same rows, re-deleting and double-counting
     *    into two run rows. The scheduler guard is real for a job that runs
     *    inline; here it guards nothing.
     *
     *  · And moving it to middleware without `expireAfter()` swaps that for a
     *    worse failure: the middleware lock does NOT expire, so a worker killed
     *    mid-sweep (a deploy, an OOM, the 900s timeout) leaves it held for ever
     *    and retention silently never runs again. The EXPIRY is the part that
     *    makes this correct, not the lock.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('compliance:retention-sweep'))
            ->expireAfter($this->timeout + 60)
            ->dontRelease()];
    }

    public function handle(PersonalDataRegistry $registry): void
    {
        /*
        | ⚠️ FR-030, RESOLVED ONCE AND PASSED DOWN. A legal hold suspends an erasure
        | REQUEST, and retention needs nobody to request anything — so a hold that
        | only touched `data_requests` would let this job delete the exact rows a
        | court ordered kept, on a schedule, with the hold sitting green beside it.
        |
        | Passed as ids rather than read by each module: `Compliance` names no
        | module's table, and no module imports `LegalHold`.
        */
        $exemptUserIds = array_values(array_unique(array_map(
            intval(...),
            LegalHold::query()->inForce()->pluck('subject_user_id')->all(),
        )));

        $processed = 0;
        $rows = [ExpiryBehaviour::Delete->value => 0, ExpiryBehaviour::Anonymise->value => 0, ExpiryBehaviour::Archive->value => 0];
        $findings = [];
        $findingsCount = 0;

        foreach (DataCategory::query()->orderBy('key')->get() as $category) {
            if (! $category->expires()) {
                continue;
            }

            $owner = $registry->forCategory($category->key);
            $behaviour = $category->expiry_behaviour;

            if ($owner === null || $behaviour === null) {
                $findingsCount++;
                $findings[] = ['category' => $category->key, 'reason' => 'no_owner'];

                continue;
            }

            $processed++;

            /*
            | ⚠️ THE BOUND IS A DATE COMPUTED HERE, IN PHP. Evaluated in SQL as
            | `created_at + INTERVAL n DAY` it raises ERROR 1441 past year 9999 on
            | MySQL — killing the whole sweep — while SQLite returns NULL and
            | expires nothing at all, with no error in either direction. Neither
            | failure is visible on a developer's machine.
            */
            $before = CarbonImmutable::now()->subDays((int) $category->retain_days);

            try {
                $rows[$behaviour->value] += $this->walk($registry, $category->key, $before, $behaviour, $exemptUserIds);
            } catch (Throwable $e) {
                /*
                | One category's failure must not end the night for the twelve
                | behind it — a retention sweep that stops at the first error is a
                | legal deadline missed for every category after it in the
                | alphabet.
                */
                report($e);

                $findingsCount++;

                if (count($findings) < RetentionSweepRun::FINDINGS_CAP) {
                    // The MESSAGE, never a row: FR-031 forbids logging the data
                    // itself, and an exception from a walk can carry values.
                    $findings[] = ['category' => $category->key, 'reason' => $e::class];
                }
            }
        }

        RetentionSweepRun::query()->create([
            'ran_at' => now(),
            'categories_processed' => $processed,
            'rows_deleted' => $rows[ExpiryBehaviour::Delete->value],
            'rows_anonymised' => $rows[ExpiryBehaviour::Anonymise->value],
            'rows_archived' => $rows[ExpiryBehaviour::Archive->value],
            'findings' => $findings === [] ? null : $findings,
            'findings_count' => $findingsCount,
        ]);
    }

    /**
     * @param  list<int>  $exemptUserIds
     */
    private function walk(
        PersonalDataRegistry $registry,
        string $category,
        CarbonImmutable $before,
        ExpiryBehaviour $behaviour,
        array $exemptUserIds,
    ): int {
        $owner = $registry->forCategory($category);

        if ($owner === null) {
            return 0;
        }

        $total = 0;

        for ($batch = 0; $batch < self::MAX_BATCHES_PER_CATEGORY; $batch++) {
            $done = $owner->expire($category, $before, $behaviour, self::BATCH, $exemptUserIds);
            $total += $done;

            /*
            | Below the limit means there is nothing left — the contract's own
            | definition, and what makes an interrupted sweep resumable rather than
            | a walk that has to start from the beginning.
            */
            if ($done < self::BATCH) {
                break;
            }
        }

        return $total;
    }
}
