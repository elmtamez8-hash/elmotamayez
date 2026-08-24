<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Shared\Contracts\SessionAttendanceDirectory;

/**
 * LiveSessions' answer to "did this person hold a seat?".
 *
 * Queries run without the workspace scope on purpose: a student books with many
 * teachers and asks to watch a recording before any workspace is current, so
 * scoping here would return nothing and silently deny every playback. The guard
 * is the student's own id, which is stricter than a workspace filter, not
 * looser — the same reasoning as EloquentEnrollmentDirectory.
 *
 * A late cancellation still counts. The seat was charged for (FR-010), so
 * withholding the recording would be taking payment and giving nothing.
 */
class EloquentSessionAttendanceDirectory implements SessionAttendanceDirectory
{
    private const ENTITLING = [BookingStatus::Booked, BookingStatus::CancelledLate];

    public function hasBookingForLesson(User $user, int $lessonId): bool
    {
        $sessionId = Lesson::query()
            ->withoutWorkspaceScope()
            ->whereKey($lessonId)
            ->value('class_session_id');

        if ($sessionId === null) {
            return false;
        }

        return SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('class_session_id', $sessionId)
            ->whereIn('status', self::ENTITLING)
            ->exists();
    }

    public function hasSeatInSession(User $user, int $classSessionId): bool
    {
        /*
        | ⚠️ `occupiesSeat()` ONLY, WHICH IS NARROWER THAN `ENTITLING` ABOVE. A
        | late cancellation still entitles the RECORDING — the seat was charged
        | for — but it does not put the person in the room of a session they
        | pulled out of. Two questions, two predicates; sharing one constant is
        | how the second answer quietly becomes the first.
        */
        $statuses = [];

        foreach (BookingStatus::cases() as $status) {
            if ($status->occupiesSeat()) {
                $statuses[] = $status;
            }
        }

        return SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('class_session_id', $classSessionId)
            ->whereIn('status', $statuses)
            ->exists();
    }

    /**
     * ⚠️ THE HOST IS EXCLUDED, and without that the teacher earns attendance
     * points for every lesson they teach and tops their own students' board for
     * ever. The host's attendance row exists on purpose — CloseClassSession
     * judges delivery from it — so the exclusion has to happen at the read.
     *
     * `Attendance::scopeExcludingHost()` rather than a fourth hand-written
     * `where('student_user_id', '!=', ...)`: the class register and the session
     * report already read the same rule, and one rule written in three places is
     * how the third place gets it wrong.
     *
     * @return list<int>
     */
    public function attendeeUserIds(int $classSessionId): array
    {
        $session = ClassSession::query()
            ->withoutWorkspaceScope()
            ->with('teacherProfile:id,user_id')
            ->find($classSessionId);

        if ($session === null) {
            return [];
        }

        $rows = Attendance::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $classSessionId)
            ->where('status', '!=', AttendanceStatus::Absent->value)
            ->excludingHost($session)
            ->pluck('student_user_id');

        $ids = [];

        foreach ($rows as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * ⚠️ THE ATTENDANCE TABLE, NOT THE BOOKINGS ONE — see the contract. Only
     * `absent` fails: present, late and excused all count, the last because an
     * excusal is the teacher's decision that the absence is not held against
     * the student.
     *
     * @param  list<int>  $classSessionIds
     * @return list<int>
     */
    public function attendedSessionIds(User $user, array $classSessionIds): array
    {
        if ($classSessionIds === []) {
            return [];
        }

        $rows = Attendance::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->whereIn('class_session_id', $classSessionIds)
            ->where('status', '!=', AttendanceStatus::Absent->value)
            ->pluck('class_session_id');

        $ids = [];

        foreach ($rows as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * ⚠️ `DISTINCT` ON THE SESSION, NOT A ROW COUNT. The unique index on
     * `attendances` makes one row per student per session today, so the two
     * numbers agree — and a count that relies on an index it does not name is one
     * schema change away from letting a student rate a teacher they met once.
     *
     * Excused counts, only `absent` fails — the rule written three times in the
     * contract this implements.
     */
    public function attendedSessionCountInWorkspace(User $user, int $workspaceId): int
    {
        return Attendance::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('student_user_id', $user->getKey())
            ->where('status', '!=', AttendanceStatus::Absent->value)
            ->distinct()
            ->count('class_session_id');
    }

    /**
     * ⚠️ CANCELLED AND SUSPENDED SESSIONS ARE SKIPPED, NOT TREATED AS THE
     * PREVIOUS ONE. Nobody could attend a class that did not happen, so gating
     * on it would shut the rest of the course behind it — and a freeze, which
     * exists to protect the student, would become the thing that locks them out.
     *
     * @param  list<int>  $classSessionIds
     * @return array<int, int|null>
     */
    public function previousCountableSessionIds(array $classSessionIds): array
    {
        if ($classSessionIds === []) {
            return [];
        }

        $targets = ClassSession::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $classSessionIds)
            ->get(['id', 'workspace_id', 'course_id', 'starts_at']);

        $out = [];

        foreach ($targets as $target) {
            $out[(int) $target->getKey()] = null;
        }

        if ($targets->isEmpty()) {
            return $out;
        }

        /*
         | One query for the whole candidate pool rather than one per target:
         | the timetable asks about twenty sessions at once, and the index
         | `(workspace_id, course_id, starts_at)` added in step 11b is what makes
         | this cheap. Candidates are every countable session of the courses
         | involved that started before the latest target.
         */
        $candidates = ClassSession::query()
            ->withoutWorkspaceScope()
            ->whereIn('workspace_id', $targets->pluck('workspace_id')->unique()->all())
            ->whereIn('course_id', $targets->pluck('course_id')->filter()->unique()->all())
            ->whereIn('status', [ClassSessionStatus::Completed->value, ClassSessionStatus::Live->value, ClassSessionStatus::Interrupted->value])
            ->where('starts_at', '<', $targets->max('starts_at'))
            ->orderByDesc('starts_at')
            ->get(['id', 'workspace_id', 'course_id', 'starts_at']);

        foreach ($targets as $target) {
            if ($target->course_id === null) {
                continue;
            }

            $previous = $candidates->first(
                fn (ClassSession $candidate): bool => (int) $candidate->workspace_id === (int) $target->workspace_id
                    && (int) $candidate->course_id === (int) $target->course_id
                    && $candidate->starts_at < $target->starts_at,
            );

            $out[(int) $target->getKey()] = $previous === null ? null : (int) $previous->getKey();
        }

        return $out;
    }

    /** @return list<int> */
    public function bookedLessonIdsFor(User $user): array
    {
        $sessionIds = SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->whereIn('status', self::ENTITLING)
            ->pluck('class_session_id');

        $ids = Lesson::query()
            ->withoutWorkspaceScope()
            ->whereIn('class_session_id', $sessionIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }
}
