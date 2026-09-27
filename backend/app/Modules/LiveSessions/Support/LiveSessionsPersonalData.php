<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use App\Shared\Support\GuardianPermission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * LiveSessions's half of the data-rights contract (spec 013).
 *
 * ⚠️ REGISTERED WITH ONE TAGGED LINE in this module's provider, and `Compliance`
 * never names a table here. That is the whole reason a requirement crossing
 * thirteen schemas does not violate Constitution III.
 *
 * @see PersonalDataOwner
 */
class LiveSessionsPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'livesessions';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['attendance_record', 'freeze_period'];
    }

    /**
     * ⚠️ A GENERATOR, NOT AN ARRAY. `SC-014` measures fifty thousand rows, and
     * thirteen full arrays held in memory while each is JSON-encoded peaks at
     * twice the serialised size — above the worker's ceiling, with `tries: 1`.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        if (! $subject->mayReceive(GuardianPermission::Attendance)) {
            yield from ExportWalk::none(...$this->describe());

            return;
        }

        $userId = $subject->user->getKey();

        /*
        | ⚠️ THE HOST'S OWN ROW IS EXCLUDED, and it exists on purpose so it has to
        | be excluded on purpose. `CloseClassSession` judges delivery — the
        | teacher's pay — from an attendance row belonging to the teacher, so it
        | cannot be removed; what must not happen is its being read as a student's
        | register entry. `Attendance::scopeExcludingHost()` says this for ONE
        | session; a person-shaped walk cannot use it, so the same rule is spelled
        | here against the session's own host. One rule in two places is how the
        | second place gets it wrong, which is why the reason travels with it.
        */
        yield from ExportWalk::keyed(
            'attendance_record',
            Attendance::query()
                ->withoutWorkspaceScope()
                ->leftJoin('class_sessions', 'class_sessions.id', '=', 'attendances.class_session_id')
                ->leftJoin('teacher_profiles', 'teacher_profiles.id', '=', 'class_sessions.teacher_profile_id')
                ->where('attendances.student_user_id', $userId)
                ->where(function (Builder $query) use ($userId): void {
                    $query->whereNull('teacher_profiles.user_id')
                        ->orWhere('teacher_profiles.user_id', '!=', $userId);
                })
                ->select([
                    'attendances.*',
                    'class_sessions.title as session_title',
                    'class_sessions.starts_at as session_starts_at',
                ]),
            fn (Attendance $attendance): array => [
                'uuid' => $attendance->uuid,
                'session_title' => $attendance->getAttribute('session_title'),
                'session_starts_at' => ExportWalk::at($attendance->getAttribute('session_starts_at')),
                'status' => $attendance->status,
                'source' => $attendance->source,
                'stay_seconds' => $attendance->stay_seconds,
                'first_joined_at' => ExportWalk::at($attendance->first_joined_at),
                // Why a teacher changed the mark. It is a statement about this
                // person, written about them, and FR-016 does not let us keep the
                // part of the record that is least flattering.
                'override_reason' => $attendance->override_reason,
                'overridden_at' => ExportWalk::at($attendance->overridden_at),
            ],
            column: 'attendances.id',
        );

        yield from ExportWalk::keyed(
            'attendance_record',
            SessionBooking::query()
                ->withoutWorkspaceScope()
                ->leftJoin('class_sessions', 'class_sessions.id', '=', 'session_bookings.class_session_id')
                ->where('session_bookings.student_user_id', $userId)
                ->select([
                    'session_bookings.*',
                    'class_sessions.title as session_title',
                    'class_sessions.starts_at as session_starts_at',
                ]),
            fn (SessionBooking $booking): array => [
                'uuid' => $booking->uuid,
                'session_title' => $booking->getAttribute('session_title'),
                'session_starts_at' => ExportWalk::at($booking->getAttribute('session_starts_at')),
                'status' => $booking->status,
                'booked_at' => ExportWalk::at($booking->booked_at),
                'cancelled_at' => ExportWalk::at($booking->cancelled_at),
                'cancellation_reason' => $booking->cancellation_reason,
            ],
            column: 'session_bookings.id',
        );

        /*
        | 049. ⚠️ FILED UNDER `attendance_record` RATHER THAN UNDER A CATEGORY OF
        | ITS OWN, and the reason is the retention it inherits: a postponement is
        | a sentence a student wrote about a lesson they had a seat in, which is
        | exactly what a cancellation reason on the booking beside it already is.
        | A second category would be a second retention number for one kind of
        | fact — and the day they drift, one of the two is wrong and nothing says
        | which.
        |
        | ⚠️ AND `private_session_requests` AND `cohort_transfer_requests` ARE
        | STILL ABSENT FROM THIS FILE. Both carry a student's own words and
        | neither is exported or swept, because `PersonalDataContractCoverageTest`
        | is a per-MODULE guard and a new table inside a registered module is
        | invisible to it — the limitation this repository records rather than
        | half-closes. Named here so the next reader knows it is a gap and not a
        | decision.
        */
        yield from ExportWalk::keyed(
            'attendance_record',
            SessionRescheduleRequest::query()
                ->withoutWorkspaceScope()
                ->leftJoin('class_sessions', 'class_sessions.id', '=', 'session_reschedule_requests.class_session_id')
                ->where('session_reschedule_requests.student_user_id', $userId)
                ->select([
                    'session_reschedule_requests.*',
                    'class_sessions.title as session_title',
                ]),
            fn (SessionRescheduleRequest $ask): array => [
                'uuid' => $ask->uuid,
                'session_title' => $ask->getAttribute('session_title'),
                'from_starts_at' => ExportWalk::at($ask->from_starts_at),
                'to_starts_at' => ExportWalk::at($ask->to_starts_at),
                'status' => $ask->status,
                // Both are statements about this person: their own reason for
                // asking, and their teacher's for refusing.
                'student_reason' => $ask->student_reason,
                'decision_reason' => $ask->decision_reason,
                'decided_at' => ExportWalk::at($ask->decided_at),
            ],
            column: 'session_reschedule_requests.id',
        );

        yield from $this->exportFreezes($userId);
    }

    /**
     * A freeze declared on THIS student — never a workspace-wide one, which
     * names nobody and is the teacher's calendar rather than a fact about a
     * person.
     *
     * ⚠️ TWO WALKS UNDER ONE KEY, because the ledger says something the periods
     * cannot: lifting a freeze DELETES its `freeze_periods` row, so a lifted
     * freeze survives only as its `freeze_period_starts` line. The ledger has no
     * model (it is read by one COUNT and written by one INSERT), so it is walked
     * by id here rather than given a model that would then owe
     * `BelongsToWorkspace` and an isolation test for a table no screen reads.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    private function exportFreezes(mixed $userId): iterable
    {
        yield from ExportWalk::keyed(
            'freeze_period',
            FreezePeriod::query()
                ->withoutWorkspaceScope()
                ->where('student_user_id', $userId),
            fn (FreezePeriod $period): array => [
                'uuid' => $period->uuid,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                // The teacher's words about why this student's lessons stopped.
                'reason' => $period->reason,
            ],
        );

        $page = [];

        foreach (DB::table('freeze_period_starts')->where('student_user_id', $userId)->lazyById(500) as $start) {
            $page[] = [
                'declared_starts_on' => substr((string) $start->starts_on, 0, 10),
                'declared_at' => ExportWalk::at($start->created_at),
            ];

            if (count($page) === 500) {
                yield 'freeze_period' => $page;

                $page = [];
            }
        }

        if ($page !== []) {
            yield 'freeze_period' => $page;
        }
    }

    /**
     * ⚠️ THE MODE IS RECEIVED, NEVER INVENTED, and the walk is `chunkById` (for
     * anonymising, where the row survives and needs a cursor) or a
     * `->limit(n)->delete()` loop (for deleting). Never `chunk`: it paginates by
     * OFFSET while the predicate shrinks underneath it, so every page after the
     * first skips as many rows as the last one fixed — and reports success.
     */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        if ($mode !== ErasureMode::Anonymise) {
            return 0;
        }

        /*
        | ⚠️ THE BOOKINGS AND THE ATTENDANCE ROWS SURVIVE, AND DELETING THEM WOULD
        | BREAK A NIGHTLY INVARIANT FOR EVER. A seat is what
        | `ReconcileCreditBalancesJob` counts against consumption entries — "one
        | consumption entry per seat of a charged session" is one of the two checks
        | that can see a session which was never charged, precisely because it comes
        | from OUTSIDE the path that writes both sides. Remove an erased student's
        | seats and every session they sat reports a drift, every night, with no
        | cause anybody can find.
        |
        | The identity is severed at the `users` row instead. What is cleared here
        | is the FREE TEXT: a teacher's note about why a mark was changed, and a
        | student's own words about why they cancelled. Neither is needed by any
        | count, and both are statements about a named person.
        */
        $userId = $subject->user->getKey();

        $cleared = Attendance::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $userId)
            ->whereNotNull('override_reason')
            ->limit($limit)
            ->update(['override_reason' => null]);

        if ($cleared >= $limit) {
            return $cleared;
        }

        $cleared += SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $userId)
            ->whereNotNull('cancellation_reason')
            ->limit($limit - $cleared)
            ->update(['cancellation_reason' => null]);

        if ($cleared >= $limit) {
            return $cleared;
        }

        // The row stays — it is the record that a lesson moved, which the group
        // it moved for can still see. What goes is the writing about a person.
        $cleared += SessionRescheduleRequest::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $userId)
            ->whereNotNull('student_reason')
            ->limit($limit - $cleared)
            ->update(['student_reason' => null]);

        if ($cleared >= $limit) {
            return $cleared;
        }

        return $cleared + $this->deleteFreezes($userId, $limit - $cleared);
    }

    /**
     * ⛔ A FREEZE ON ONE STUDENT IS DELETED, NEVER NULLED — ON BOTH TABLES.
     *
     * «Anonymise» everywhere else in this file means the row stays and stops
     * pointing at anyone. On `freeze_periods` and `freeze_period_starts` a null
     * `student_user_id` does not point at nobody: it is THE WHOLE WORKSPACE. A
     * nulled period would suspend every student of that teacher for its dates
     * (`FreezePeriod::scopeCovering()` reads null as «everyone»), and a nulled
     * ledger line would be counted against the workspace's own monthly ceiling
     * and refuse the teacher's next real freeze. So the only way these rows stop
     * naming a person is to go.
     *
     * Nothing counts them once the student is gone: the monthly ceiling is read
     * per scope, and the workspace scope is `whereNull` — which a student's line
     * never matched and still does not.
     *
     * ⚠️ A QUERY-BUILDER DELETE, SO `FreezePeriod::booted()` DOES NOT ANNOUNCE
     * IT, AND THAT IS DECIDED RATHER THAN MISSED. `FreezePeriodChanged` makes
     * Payments re-date the subscription the freeze extended; for an account
     * being erased (or a freeze three years over) that is a recomputation of an
     * end date nobody will reach — and the lift path (`DeleteFreezePeriod`)
     * that also gives seats back is not what this is either: the student is
     * leaving, not resuming.
     *
     * @return int rows removed, across both tables, never more than `$limit`
     */
    private function deleteFreezes(mixed $userId, int $limit): int
    {
        $periods = DB::table('freeze_periods')
            ->where('student_user_id', $userId)
            ->limit($limit)
            ->delete();

        if ($periods >= $limit) {
            return $periods;
        }

        return $periods + DB::table('freeze_period_starts')
            ->where('student_user_id', $userId)
            ->limit($limit - $periods)
            ->delete();
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     *
     * @param  list<int>  $exemptUserIds  subjects under a live hold — their rows stay.
     */
    public function expire(
        string $category,
        CarbonImmutable $before,
        ExpiryBehaviour $mode,
        int $limit,
        array $exemptUserIds = [],
    ): int {
        if ($category === 'freeze_period' && $mode === ExpiryBehaviour::Delete) {
            return $this->expireFreezes($before, $limit, $exemptUserIds);
        }

        if ($category !== 'attendance_record' || $mode !== ExpiryBehaviour::Anonymise) {
            return 0;
        }

        /*
        | ⚠️ THE ROW STAYS AND THE FREE TEXT GOES — THE SAME DEFINITION `erase()`
        | USES HERE, AND FOR THE SAME REASON. A seat is what
        | `ReconcileCreditBalancesJob` counts against consumption entries; delete
        | three-year-old attendance and every session behind that line reports a
        | drift, every night, with no cause anybody can find. And nulling
        | `student_user_id` is not available either: the column is NOT NULL, and
        | `->change()` on it re-declares the column and REBUILDS the table on
        | SQLite — a table carrying seven indexes, none of them asserted anywhere.
        |
        | What genuinely has an age is the WRITING: a teacher's note explaining why
        | they changed a mark, and a student's own words about why they cancelled.
        | Neither is needed by any count, and both are statements about a named
        | person that nobody will read again after three years.
        |
        | ⚠️ AND THE `whereNotNull` IS THE CONVERGENCE GUARD, not an optimisation.
        | Without it every old row matches again tomorrow, is counted again in
        | `retention_sweep_runs`, and `SC-010`'s "two runs, same state" holds for
        | the data while the log says two different numbers.
        */
        $cutoff = $before->toDateTimeString();

        $cleared = Attendance::query()
            ->withoutWorkspaceScope()
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('override_reason')
            ->when($exemptUserIds !== [], fn ($query) => $query->whereNotIn('student_user_id', $exemptUserIds))
            ->limit($limit)
            ->update(['override_reason' => null]);

        if ($cleared >= $limit) {
            return $cleared;
        }

        $cleared += SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('cancellation_reason')
            ->when($exemptUserIds !== [], fn ($query) => $query->whereNotIn('student_user_id', $exemptUserIds))
            ->limit($limit - $cleared)
            ->update(['cancellation_reason' => null]);

        if ($cleared >= $limit) {
            return $cleared;
        }

        return $cleared + SessionRescheduleRequest::query()
            ->withoutWorkspaceScope()
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('student_reason')
            ->when($exemptUserIds !== [], fn ($query) => $query->whereNotIn('student_user_id', $exemptUserIds))
            ->limit($limit - $cleared)
            ->update(['student_reason' => null]);
    }

    /**
     * `freeze_period` ages out by DELETION, for the reason {@see deleteFreezes()}
     * gives: on these two tables the pointer cannot be severed without becoming
     * «the whole workspace».
     *
     * ⚠️ AGED BY THE FREEZE'S OWN DATES, NEVER BY `created_at`. A period is
     * declared ahead of the days it covers, so a created-at age could remove one
     * still in force; `ends_on` cannot. The ledger's line is aged by the
     * `starts_on` it counts — a line only ever matters inside its own month.
     *
     * ⚠️ AND ONLY A STUDENT'S ROWS. A workspace-wide freeze names nobody, so the
     * category does not describe it — and restricting to non-null first is also
     * what makes the legal-hold exemption safe: `NULL NOT IN (…)` is NULL and
     * would spare nothing, but no null reaches that clause.
     *
     * @param  list<int>  $exemptUserIds
     */
    private function expireFreezes(CarbonImmutable $before, int $limit, array $exemptUserIds): int
    {
        $cutoff = $before->toDateString();

        $periods = DB::table('freeze_periods')
            ->whereNotNull('student_user_id')
            ->where('ends_on', '<', $cutoff)
            ->when($exemptUserIds !== [], fn ($query) => $query->whereNotIn('student_user_id', $exemptUserIds))
            ->limit($limit)
            ->delete();

        if ($periods >= $limit) {
            return $periods;
        }

        return $periods + DB::table('freeze_period_starts')
            ->whereNotNull('student_user_id')
            ->where('starts_on', '<', $cutoff)
            ->when($exemptUserIds !== [], fn ($query) => $query->whereNotIn('student_user_id', $exemptUserIds))
            ->limit($limit - $periods)
            ->delete();
    }
}
