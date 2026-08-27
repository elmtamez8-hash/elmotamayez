<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Shared\Actions\Action;
use Illuminate\Support\Collection;

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
    /** @return Collection<int, SessionBooking> */
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
                // The Resource asks every published session where its recording
                // went; without this that is one query per booked hour.
                ->with('recordingLesson')])
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
