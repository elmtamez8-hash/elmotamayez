<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;
use Carbon\CarbonImmutable;

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
     * Whether this user holds a seat in this session RIGHT NOW (010 · FR-018).
     *
     * ⚠️ A DIFFERENT QUESTION FROM THE ONE ABOVE, AND FROM ATTENDANCE. The lesson
     * form asks about a recording that already exists; this one is asked about a
     * session that has not happened yet, which is what the room under it is for.
     * And it is not `attendedSessionIds()`: a student who booked but has not
     * arrived belongs in the room before the lesson starts — that is where they
     * ask what to bring.
     *
     * Seat-occupying statuses only, so a cancelled booking closes the room with
     * the seat rather than leaving a reader nobody expects.
     */
    public function hasSeatInSession(User $user, int $classSessionId): bool;

    /**
     * Whether the host put this person out of this session (017 · «إخراج»).
     *
     * ⚠️ A SEPARATE QUESTION FROM THE SEAT, AND THE ROOM'S CHAT NEEDED IT. The
     * removal is recorded on the attendance row and read at the broadcast door,
     * so a removed student is out of the video — and used to carry straight on
     * typing the same thing into the room chat, which every seat holder reads.
     * The teacher's only remaining instrument was a workspace-wide BAN: recorded,
     * appealable, and covering every thread with that teacher for ever, which is
     * the wrong weight for one hour of one lesson. Locking the room silences the
     * whole class instead of the one person.
     *
     * The seat is deliberately untouched by a removal — cancelling it would
     * repossess a session the student PAID for over a moment's behaviour — so no
     * seat predicate can answer this.
     */
    public function wasRemovedFromSession(User $user, int $classSessionId): bool;

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
     * How many of this teacher's sessions the student actually attended (010 · FR-030).
     *
     * ⚠️ A COUNT, NOT A LIST, BECAUSE THE CALLER ONLY EVER COMPARES IT TO A
     * THRESHOLD. `Marketplace` asks it to decide whether a student has studied
     * enough to rate their teacher, and both the gate that refuses and the screen
     * that offers read this one method — two spellings of one question is the
     * defect `ListLeaderboardScopes` was written to close.
     *
     * ⚠️ AND EXCUSED COUNTS AS ATTENDED, as everywhere else in this interface.
     * Only `absent` fails. The host's own row cannot appear: a teacher holds no
     * enrolment in their own workspace and never reaches this question.
     *
     * Scoped by workspace rather than by teacher id because that IS the teacher
     * here — a workspace is one teacher's room (constitution) — and because the
     * attendance row carries `workspace_id` and no teacher column at all.
     */
    public function attendedSessionCountInWorkspace(User $user, int $workspaceId): int;

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
     * Who is holding a seat in one session (010 · FR-043).
     *
     * ⚠️ NOT `attendeeUserIds()` ABOVE, AND THE DIFFERENCE IS THE TENSE. That one
     * answers who turned up, which is a fact about a session that has happened.
     * An announcement scoped to a session is almost always about one that has
     * NOT — the time moved, bring the textbook, it is cancelled — so asking who
     * attended would address an empty list every time and quietly reach nobody,
     * with the fan-out reporting success.
     *
     * ⚠️ AND `occupiesSeat()` RATHER THAN `ENTITLING`, the same narrower predicate
     * `hasSeatInSession()` documents: a late cancellation still entitles the
     * recording, because the seat was charged for, but the person is not coming
     * and a notice about the room is not addressed to them.
     *
     * The workspace is passed in and checked rather than trusted from the
     * session id: the id is resolved from a uuid in the caller's own workspace,
     * and this is the second lock on the same door.
     *
     * @return list<int> user ids, ascending
     */
    public function seatHolderUserIds(int $classSessionId, int $workspaceId): array;

    /**
     * Every lesson this user may watch by virtue of a seat.
     *
     * The reason issuing grants for a list does not scale with its length: read
     * entitlement once, filter in memory (SC-011).
     *
     * @return list<int>
     */
    public function bookedLessonIdsFor(User $user): array;

    /**
     * What share of one teacher's sessions this student attended in a period
     * (010 · FR-036).
     *
     * ⚠️ `float|null`, AND NULL IS NOT ZERO. Null means the teacher held no
     * countable session at all in the period — a term with no classes is not a
     * term the student missed — and the caller drops attendance from the grade
     * and re-weights the rest (FR-053). Zero means sessions were held and the
     * student was at none of them, which is a real and different statement.
     *
     * ⚠️ AND EXCUSED COUNTS AS ATTENDED, as everywhere else in this interface.
     * Only `absent` fails.
     *
     * Distinct from {@see attendedSessionCountInWorkspace()}, which counts
     * attendances alone and has no denominator: a count answers "have they
     * studied here enough to rate their teacher", and a share answers "how much
     * of the term did they turn up for". The first would report a student who
     * attended four of forty sessions as more present than one who attended
     * three of three.
     */
    public function attendanceShareInPeriod(
        User $student,
        int $workspaceId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): ?float;
}
