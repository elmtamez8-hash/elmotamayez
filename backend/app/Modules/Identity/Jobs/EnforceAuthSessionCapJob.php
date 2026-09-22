<?php

declare(strict_types=1);

namespace App\Modules\Identity\Jobs;

use App\Modules\Identity\Models\AuthSession;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Contracts\LegalHoldDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Each account keeps its newest N anonymised sign-ins (spec 038 · FR-004).
 *
 * ⚠️ AND IT LIVES IN `Identity`, OUTSIDE THE RETENTION SWEEP, which is the one
 * architectural decision this feature makes. The first design folded it into
 * `expire()`; the objection was that deletions would be logged as
 * `rows_anonymised`, a lie in an audit log — and the answer, `rows_deleted`, turned
 * out to be the same lie in the other column, because SIXTEEN live categories carry
 * `ExpiryBehaviour::Delete` with a retention. A night reporting
 * `categories_processed = 16, rows_deleted = 900` where 500 belong to no category
 * at all is worse than no log. `TransferDataOwnershipJob` — module-owned, on the
 * compliance queue, no tag and no `Shared` contract — is the shipped template.
 *
 * ⚠️ IT RUNS AFTER THE SWEEP, AND THE ORDER IS REQUIRED RATHER THAN PREFERRED:
 * anonymisation is what PRODUCES this job's candidates, so a run before it works on
 * yesterday's set.
 */
class EnforceAuthSessionCapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How many users one night may work through.
     *
     * ⚠️ ITS OWN CEILING, NOT `RunRetentionSweepJob::MAX_BATCHES_PER_CATEGORY`.
     * That one bounds batches PER CATEGORY; this one walks users, and reusing the
     * number would tie two unrelated limits together at the first tuning.
     */
    private const MAX_USERS_PER_RUN = 2000;

    /** Users discovered per page of the first query. */
    private const DISCOVERY_PAGE = 200;

    /**
     * Ids per `whereIn` statement.
     *
     * ⛔ THE LIST IS NOT BOUNDED BY ANYTHING ELSE. One account may hold tens of
     * thousands of rows past the floor — that is the REASON for the feature, not an
     * exotic case — and a single `whereIn` over all of them is
     * `ERROR 1390 Prepared statement contains too many placeholders` on MySQL
     * (65,535 bindings, server-side prepares) and `SQLITE_MAX_VARIABLE_NUMBER`
     * (32,766) here.
     */
    private const DELETE_CHUNK = 1000;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue('compliance');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        /*
        | ⚠️ `expireAfter()` IS THE LOAD-BEARING HALF, not the lock. A
        | `WithoutOverlapping` whose lock never expires is held for ever by the
        | first worker that is killed mid-run, and the cap then silently stops
        | working — for ever, with nothing on any screen.
        |
        | ⚠️ And the middleware rather than `Schedule::job()->withoutOverlapping()`:
        | that guards the DISPATCH, which for a queued job is milliseconds, and is
        | released long before the worker starts.
        */
        return [(new WithoutOverlapping('identity:auth-session-cap'))
            ->expireAfter($this->timeout + 60)
            ->dontRelease()];
    }

    public function handle(LegalHoldDirectory $holds): void
    {
        $cap = (int) PlatformSettings::get('auth.auth_session_cap_per_user', config('auth_sessions.cap_per_user'));

        /*
        | ⚠️ ZERO OR LESS IS "NO CAP", AND IT RETURNS RATHER THAN DELETING
        | EVERYTHING. An operator who empties the field means to switch the feature
        | off; reading it as "keep zero rows" would empty the platform's only
        | sign-in log, and that does not reverse.
        */
        if ($cap <= 0) {
            return;
        }

        $floorDays = (int) PlatformSettings::get(
            'auth.auth_session_cap_min_age_days',
            config('auth_sessions.cap_min_age_days'),
        );

        $floor = CarbonImmutable::now()->subDays(max($floorDays, 0));

        $seen = 0;
        $deleted = 0;
        $users = 0;

        /*
        | ⛔ A KEYSET CURSOR, NEVER AN OFFSET — and the difference is a night's
        | work silently dropped. `trim()` leaves each account holding exactly `$cap`
        | candidates, so `having('total', '>', $cap)` stops matching it and THE
        | RESULT SET SHRINKS UNDER THE WALK. An offset then steps over a set that
        | has lost precisely the rows it was meant to step past: with 300 accounts
        | over the cap, page one trims 200, the set falls to 100, page two asks for
        | offset 200 of 100 rows, gets nothing, and the run ends reporting
        | `users_trimmed = 200` as a success with 1,800 of its budget unspent.
        |
        | `AuthSessionRetention::backfillErasedAccounts()` carries this same rule in
        | its own docblock («the predicate shrinks under the walk») — it is the
        | `chunk` vs `chunkById` defect reached through a GROUP BY.
        |
        | ⚠️ It also terminates by construction: the cursor is the last id of the
        | page and the next query asks for strictly greater, so the walk advances
        | even when a page trims nothing — a held account, or one whose `trim()`
        | threw, sits behind the cursor and is retried tomorrow rather than stalling
        | the loop on itself for ever.
        */
        $cursor = 0;

        while ($seen < self::MAX_USERS_PER_RUN) {
            /*
            | ⛔ ASKED AT THE HEAD OF EVERY PAGE — never once at the top of the run.
            | A hold placed while this job is walking has to protect what is left,
            | and a delete does not reverse. The same spelling `ExecuteDataErasure`
            | uses, whose comment calls it "the third door".
            |
            | ⛔ AND A FAILURE STOPS THE RUN RATHER THAN READING AS AN EMPTY LIST.
            | Empty means "nobody is held"; treating a failed call the same way
            | deletes rows a court ordered kept. The error direction of stopping is
            | a night without a sweep; the other does not reverse.
            */
            $exempt = $holds->heldUserIds();

            $candidates = $this->overCap($cap, $floor, $exempt, $cursor);

            if ($candidates === []) {
                break;
            }

            $seen += count($candidates);
            $cursor = $candidates[count($candidates) - 1];

            foreach ($candidates as $userId) {
                try {
                    /*
                    | ⚠️ RE-ASKED BEFORE THE DELETE, AND SKIPPING HERE IS CORRECT —
                    | the mirror of the rule below. This step returns THAT USER'S
                    | rows rather than a page count, so a `continue` shortens
                    | nothing; the discovery query is where a post-selection skip
                    | would end the night for everyone behind them.
                    */
                    if (in_array($userId, $holds->heldUserIds(), true)) {
                        continue;
                    }

                    $removed = $this->trim($userId, $cap, $floor);

                    if ($removed > 0) {
                        $deleted += $removed;
                        $users++;
                    }
                } catch (Throwable $e) {
                    /*
                    | ⚠️ THE CLASS, NEVER `getMessage()`. Laravel interpolates the
                    | BINDINGS into a `QueryException`'s message, and this job's
                    | bindings are user ids and dates — which would travel to the
                    | monitoring vendor in a line nobody redacts (FR-041 ·
                    | `LogHygieneTest`). The full trace lands in `failed_jobs`, in
                    | our own database.
                    |
                    | ⚠️ And per user, so one bad row does not end the night for
                    | everybody behind it.
                    */
                    Log::error('identity.auth_session_cap.user_failed', [
                        'user_id' => $userId,
                        'exception' => $e::class,
                    ]);
                }
            }
        }

        /*
        | ⚠️ A NIGHT WITH NO LINE CANNOT BE TOLD FROM A JOB THAT HAS NOT RUN SINCE
        | MARCH. Counts and ids only — see the catch above.
        */
        Log::info('identity.auth_session_cap.swept', [
            'cap' => $cap,
            'floor_days' => $floorDays,
            'users_trimmed' => $users,
            'rows_deleted' => $deleted,
        ]);
    }

    /**
     * Who is over the cap — one page of them.
     *
     * @param  list<int>  $exempt
     * @return list<int>
     */
    private function overCap(int $cap, CarbonImmutable $floor, array $exempt, int $after): array
    {
        /*
        | ⛔ THE SELECT LIST IS EXPLICIT, AND THIS IS A MySQL-ONLY LANDMINE. A bare
        | `->groupBy('user_id')->get()` compiles to `select * … group by user_id`,
        | which is ERROR 1055 under `ONLY_FULL_GROUP_BY` — and Laravel puts that in
        | the sql_mode unconditionally for `'strict' => true`, which
        | `config/database.php` sets with no `modes` override. SQLite returns an
        | arbitrary row for the bare columns and says nothing, so the wrong form is
        | green on every shard of CI for ever while the job throws on its first
        | statement every night in production. Precedent for the right shape:
        | `DeviceRegistry`.
        */
        $rows = $this->candidates($floor)
            ->when($exempt !== [], fn (Builder $q): Builder => $q->whereNotIn('user_id', $exempt))
            // The cursor. Pre-grouping, which is exact: every row of a group
            // carries the same `user_id`, so filtering rows and filtering groups
            // are the same filter here.
            ->where('user_id', '>', $after)
            ->select('user_id')
            ->selectRaw('count(*) as total')
            ->groupBy('user_id')
            ->having('total', '>', $cap)
            ->orderBy('user_id')
            ->limit(self::DISCOVERY_PAGE)
            ->pluck('user_id');

        // Accumulated rather than mapped: a list by construction, which is what
        // the signature promises and what the analyser can see.
        $ids = [];

        foreach ($rows as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /** Delete everything this account holds past the newest `$cap`. */
    private function trim(int $userId, int $cap, CarbonImmutable $floor): int
    {
        /*
        | ⛔ DISCOVERY IS ONE QUERY AND THE DELETE IS ANOTHER, WITH THE IDS PASSED
        | AS VALUES. The spelling everybody reaches for —
        | `DELETE FROM auth_sessions WHERE id NOT IN (SELECT … FROM auth_sessions …)`
        | — is ERROR 1093 on MySQL ("You can't specify target table for update in
        | FROM clause"), and SQLite rewrites it into `rowid in (…)` so it passes
        | green here for ever.
        |
        | ⛔ `ORDER BY ended_at DESC, id DESC`, NOT `created_at`. Two reasons, both
        | measured: `created_at` is second-precision, so two sign-ins in one second
        | tie and the survivors differ between two runs that SC-006 compares; and
        | "the newest session" is a question about when it ENDED. `id` is the
        | primary key, so the order is total and the OFFSET is deterministic.
        |
        | ⚠️ `auth_sessions_eviction_index` cannot serve this — `ended_at` is not a
        | column of it at all, so no reordering of its columns would help. It does
        | serve the `(user_id, status)` prefix, making this a range seek plus a
        | filesort per user.
        */
        $ids = $this->candidates($floor)
            ->where('user_id', $userId)
            ->orderByDesc('ended_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->all();

        /*
        | ⚠️ THE CAP IS DROPPED IN PHP, NOT WITH `OFFSET … LIMIT`. An offset with no
        | limit is not portable — MySQL demands a limit beside it and the idiomatic
        | filler is a 64-bit literal — and the list is bounded by what this account
        | actually holds, which is the set being deleted anyway.
        */
        $ids = array_slice($ids, $cap);

        $removed = 0;

        foreach (array_chunk($ids, self::DELETE_CHUNK) as $chunk) {
            $removed += AuthSession::query()->whereIn('id', $chunk)->delete();
        }

        return $removed;
    }

    /**
     * The one predicate both queries share.
     *
     * ⛔ ALREADY ANONYMISED **AND** PAST THE FLOOR. `auth_sessions` is the only
     * sign-in record in the product, so a count-only cap is reachable from a live
     * keyboard: `throttle:auth` allows five a minute, and fifty sign-in/sign-out
     * cycles is about ten minutes — after which the nightly job erases the account's
     * entire baseline along with the trace of the break-in that prompted it. A row
     * that still carries its address is never a candidate, and neither is one the
     * floor has not passed.
     *
     * ⚠️ AND NO DEVICE ROW IS EVER DELETED (FR-006). `auth_sessions.device_id`
     * carries no foreign key and `AuthSession` documents it as always resolving, so
     * one dangling pointer is a 500 for the whole request rather than one row.
     *
     * @return Builder<AuthSession>
     */
    private function candidates(CarbonImmutable $floor): Builder
    {
        return AuthSession::query()
            ->where('status', AuthSession::STATUS_ENDED)
            ->whereNull('ip_hash')
            ->where('ended_at', '<', $floor);
    }
}
