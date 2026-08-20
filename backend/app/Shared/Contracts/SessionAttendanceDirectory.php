<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;

/**
 * Whether someone held a seat in the session a lesson was recorded from.
 *
 * Exists so Media can answer "may this person watch?" without reaching into
 * LiveSessions' models, which Constitution III forbids. Same shape as
 * {@see EnrollmentDirectory}: LiveSessions owns the booking and binds the
 * implementation, Media depends only on this interface.
 *
 * It is a THIRD entitlement route, not a replacement. A session recording is not
 * watchable by everyone enrolled in the course (FR-030) — only by the people who
 * booked a seat in the session itself. Publishing the recording as an ordinary
 * lesson without this would quietly widen access to the whole cohort.
 */
interface SessionAttendanceDirectory
{
    /**
     * Whether this user booked a seat in the session behind this lesson.
     *
     * Checked on every grant issue rather than cached against the session, for
     * the same reason enrolment is: entitlement that lapses must stop the next
     * request, not the next sign-in.
     */
    public function hasBookingForLesson(User $user, int $lessonId): bool;

    /**
     * Which of these sessions this student actually ATTENDED.
     *
     * ⚠️ ATTENDANCE, NOT A BOOKING, and the difference is the whole of FR-036.
     * The two methods above answer "did they hold a seat" and deliberately count
     * a late cancellation — the seat was charged for, so the recording is owed.
     * This one answers "were they there", which is what a teacher means when
     * they say the next session is earned by attending the last one. Reusing the
     * booking question would hand the next chapter to a student who paid for a
     * session they never opened.
     *
     * ⚠️ AND EXCUSED COUNTS AS ATTENDED. Only `absent` fails. An excusal is the
     * teacher's own decision that the absence is not held against the student;
     * a gate that then holds it against them contradicts the person who granted
     * it, and the exemption below would exist only to undo the teacher's other
     * hand.
     *
     * ⚠️ AND IT IS BULK. A timetable is twenty sessions, and asking per session
     * inside a Resource is 120 queries — literally the defect QueryBudgetTest
     * exists to catch.
     *
     * @param  list<int>  $classSessionIds
     * @return list<int>
     */
    public function attendedSessionIds(User $user, array $classSessionIds): array;

    /**
     * The countable sessions of one course that ended before this one started.
     *
     * ⚠️ "PREVIOUS" IS NOT id − 1. A cancelled session, or one suspended by a
     * freeze, was never attendable by anybody — gating on it would shut the
     * whole course behind a class that did not happen. So the implementation
     * returns only sessions that counted, newest first, and the caller takes the
     * head.
     *
     * @param  list<int>  $classSessionIds
     * @return array<int, int|null> keyed by session id; null means there is no
     *                              countable session before it
     */
    public function previousCountableSessionIds(array $classSessionIds): array;

    /**
     * Who actually attended one session — EXCLUDING THE HOST (spec 009).
     *
     * ⚠️ THE TEACHER HAS AN ATTENDANCE ROW ON PURPOSE, and it is not a student
     * row. `CloseClassSession` judges delivery — the teacher's pay — from it, so
     * it cannot simply be removed. But awarding attendance points from it would
     * hand the teacher experience for every lesson they teach and seat them at the
     * top of their own students' leaderboard, permanently, by construction. Same
     * rule already applies to the class register and to the session report, which
     * is exactly why it is `Attendance::scopeExcludingHost()` and not a third
     * hand-written condition.
     *
     * ⚠️ AND EXCUSED COUNTS AS ATTENDED, as everywhere else in this interface.
     * Only `absent` fails.
     *
     * Exists because `AttendanceConfirmed` carries the SESSION, not the people:
     * the listener has an event and needs a list, and asking LiveSessions' models
     * directly is what Constitution III forbids.
     *
     * @return list<int> user ids
     */
    public function attendeeUserIds(int $classSessionId): array;

    /**
     * Every lesson this user may watch by virtue of a seat.
     *
     * The reason issuing grants for a list does not scale with its length: read
     * entitlement once, filter in memory (SC-011).
     *
     * @return list<int>
     */
    public function bookedLessonIdsFor(User $user): array;
}
