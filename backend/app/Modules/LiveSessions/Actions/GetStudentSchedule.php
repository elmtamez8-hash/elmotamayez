<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\Collection;

/**
 * One student's upcoming sessions, across every teacher they study with.
 *
 * A platform-owned read path (Constitution I). It deliberately crosses workspace
 * boundaries — a student has one timetable, not one per teacher — and the guard
 * is row ownership: the query is keyed on the student's own id, which is
 * stricter than a workspace filter, not looser.
 *
 * The mirror-image rule matters just as much: nothing here may ever be reached
 * by a teacher. A teacher seeing this list would see their student's sessions
 * with a competitor, which is the exact leak the ownership layers exist to
 * prevent (PlatformOwnershipTest asserts both directions).
 *
 * ⚠️ SPEC 029 GIVES THIS ACTION A SECOND CALLER, AND THE PROMISE ABOVE STILL
 * HOLDS. `ScheduleController@children` reaches it for a GUARDIAN — who stands in
 * for the CHILD, not for a teacher. The distinction is the whole of it: the
 * guardian relation is the child's own household reading the child's own
 * timetable, gated on `GuardianPermission::Schedule`, and the query stays keyed
 * on the student's id. A teacher acquires nothing by it — `ChildScheduleGuardTest`
 * asserts that the child's own teacher is refused — so «nothing here may ever be
 * reached by a teacher» is unchanged, and the file itself needed no edit for
 * the new route to be correct.
 *
 * ⚠️ SPEC 021 DELIBERATELY LEAVES THIS FILE UNTOUCHED, AND THAT IS WHAT MAKES
 * FR-025د TRUE BY CONSTRUCTION.
 *
 * Q3 hides an unassigned session — one belonging to no group in a course that
 * runs in groups — from DISCOVERY, which is `ClassSessionController@index`. It
 * must not hide it from the person who already holds a seat in it: they booked
 * it, they paid for it, they may have attended it and the recording it produced
 * is theirs by that seat. «حصصي» is built from `session_bookings` and asks no
 * question about a group at all, so a seat stays visible to its owner whether
 * the session was ever assigned or not.
 *
 * A cohort filter added here would be exactly the reach-back FR-025د forbids:
 * a judgement about what is ON OFFER, applied to a right already acquired.
 */
class GetStudentSchedule extends Action
{
    /**
     * ⚠️ AN **ELOQUENT** COLLECTION, AND THE ANNOTATION IS LOAD-BEARING. It was
     * typed as the base `Illuminate\Support\Collection` while every path here
     * returns Eloquent's — `sortBy`/`take`/`values` all preserve the class — so
     * the declared type was simply wrong, and the first caller to reach for
     * `load()` on the result got a PHPStan error about a method that exists at
     * runtime. `ScheduleController@children` is that caller: it eager-loads the
     * course and the teacher for the guardian's payload.
     *
     * @return Collection<int, SessionBooking>
     */
    public function handle(User $student, int $limit = 50): Collection
    {
        // A subquery rather than whereHas(): the constraint has to drop the
        // workspace scope, and building it from the model's own query keeps that
        // explicit instead of hidden inside a relation callback.
        $upcoming = ClassSession::query()
            ->withoutWorkspaceScope()
            ->where('ends_at', '>=', now())
            ->whereIn('status', [ClassSessionStatus::Scheduled, ClassSessionStatus::Live])
            ->select('id');

        return SessionBooking::query()
            // Crosses workspaces on purpose — see the class docblock.
            ->withoutWorkspaceScope()
            ->where('student_user_id', $student->getKey())
            ->where('status', BookingStatus::Booked)
            ->whereIn('class_session_id', $upcoming)
            ->with(['classSession' => fn ($query) => $query
                ->withoutWorkspaceScope()
                ->with([
                    // The Resource asks every published session where its
                    // recording went; without this that is one query per booked
                    // hour.
                    'recordingLesson',
                    /*
                     | ⚠️ THE SUBJECT AND THE TEACHER, AND THE SCOPE IS DROPPED AT
                     | EVERY LEVEL. Both models carry `BelongsToWorkspace`, and
                     | this list crosses workspaces by design — so a scoped nested
                     | load answers null for every row whose teacher is not the
                     | reader's fallback workspace, and the timetable renders a
                     | lesson with no subject and no teacher under a green 200.
                     |
                     | Eager rather than read from the Resource: a Resource runs
                     | once per row, so reaching for `$session->course` inside one
                     | is an N+1 by construction — and an N+1 that would each
                     | carry the scope.
                     */
                    'course' => fn ($courses) => $courses->withoutWorkspaceScope(),
                    // Not `user:id,name` — `users` has no `name` column; it is an
                    // accessor over `first_name`/`last_name`, and a constrained
                    // load that omits those renders a blank name with no error
                    // anywhere (six call sites shipped that way in spec 010).
                    'teacherProfile' => fn ($profiles) => $profiles->withoutWorkspaceScope()->with('user'),
                ])])
            ->get()
            ->sortBy(fn (SessionBooking $booking): string => $booking->classSession?->starts_at->toIso8601String() ?? '')
            ->take($limit)
            ->values();
    }

    /** The next one, or null. Drives the countdown widget (FR-053 · FR-055). */
    public function next(User $student): ?SessionBooking
    {
        return $this->handle($student, 1)->first();
    }
}
