<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Enums\BookingStatus;
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
