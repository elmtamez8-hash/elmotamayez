<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use App\Shared\Support\GuardianPermission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

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
        return ['attendance_record'];
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

        return $cleared + SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $userId)
            ->whereNotNull('cancellation_reason')
            ->limit($limit - $cleared)
            ->update(['cancellation_reason' => null]);
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     */
    public function expire(string $category, CarbonImmutable $before, ExpiryBehaviour $mode, int $limit): int
    {
        // TODO(013-US5): process rows of $category older than $before.
        return 0;
    }
}
