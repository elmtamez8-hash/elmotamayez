<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;

/**
 * Whether a student's access is currently withheld for money owed.
 *
 * Exists so Media can refuse a high-value asset, and LiveSessions can refuse a
 * booking, without reaching into Payments' models. Payments owns the balance and
 * binds the implementation; the callers depend only on this interface. Same
 * shape as {@see EnrollmentDirectory}, and asked at the same moments — issuing a
 * grant, and taking a seat.
 *
 * ⚠️ Withholding is BY COURSE, never by workspace. A student who owes for
 * physics and has paid for maths must keep the maths notes: answering per
 * workspace closes both, which is not a rounding error but the wrong answer for
 * a paid-up course.
 *
 * ⚠️ And it is FORBIDDEN inside an API Resource. A Resource runs once per row,
 * so a call there is an N+1 by construction — read entitlement once with
 * {@see self::withheldCourseIdsFor()} and filter in memory (SC-011).
 */
interface AccountStanding
{
    /**
     * Whether this student is withheld in this specific course right now.
     *
     * Derived, not stored: there is no `access_holds` table, because a stored
     * flag is a row someone must remember to clear, and FR-033 promises the hold
     * lifts "immediately, with no manual intervention". Deriving it makes that
     * true by construction — the same shape as `is_publicly_listed`.
     */
    public function isWithheld(User $student, int $courseId): bool;

    /**
     * Every course in which this student is currently withheld.
     *
     * The bulk form is mandatory, not a convenience: both sibling contracts
     * carry one for the same reason (EnrollmentDirectory::activeCourseIdsFor,
     * SessionAttendanceDirectory::bookedLessonIdsFor). Issuing grants for a list
     * of lessons must not scale with the length of the list.
     *
     * @return list<int>
     */
    public function withheldCourseIdsFor(User $student): array;
}
