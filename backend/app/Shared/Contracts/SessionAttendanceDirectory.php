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
     * Every lesson this user may watch by virtue of a seat.
     *
     * The reason issuing grants for a list does not scale with its length: read
     * entitlement once, filter in memory (SC-011).
     *
     * @return list<int>
     */
    public function bookedLessonIdsFor(User $user): array;
}
