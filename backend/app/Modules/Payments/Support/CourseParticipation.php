<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Shared\Contracts\EnrollmentDirectory;

/**
 * Is this person party to this course? — the guard on every priced surface.
 *
 * ⚠️ IT IS ABOUT THE PRICE, NOT ABOUT THE CONTENT. A total is
 * `(approved rate + operating fee) × credits` grossed up for the gateway, and
 * the platform's two components are the SAME CONSTANTS for everyone. So a
 * stranger who could read one course's totals for two package sizes could solve
 * for both constants and then invert any other course's total back to its
 * teacher's approved settlement rate, exactly. Several package sizes make the
 * system overdetermined, so rounding hides nothing.
 *
 * That is why signed-in is not enough, and why the answer for a stranger is 403
 * rather than an unpriced or empty list.
 *
 * Workspace membership counts alongside enrolment, and that is the FIRST
 * purchase rather than a loophole: a student buying credits for their first
 * session has no enrolment yet, and requiring one would leave the packages
 * screen reachable only by people who no longer need it.
 */
class CourseParticipation
{
    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

    public function isPartyTo(User $user, Course $course): bool
    {
        return $this->enrollments->hasActiveEnrollment($user, (int) $course->getKey())
            || $this->enrollments->hasActiveEnrollmentInWorkspace($user, (int) $course->workspace_id)
            || $user->workspaces()->whereKey($course->workspace_id)->exists();
    }
}
