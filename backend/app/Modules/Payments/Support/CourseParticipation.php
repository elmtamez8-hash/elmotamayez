<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\EnrollmentDirectory;

/**
 * Is this person a BUYER on this course? — the guard on every priced surface.
 *
 * ⚠️ IT IS ABOUT THE PRICE, NOT ABOUT THE CONTENT. A total is
 * `(approved rate + operating fee) × credits` grossed up for the gateway, and
 * the platform's two components are the SAME CONSTANTS for everyone. So anyone
 * who can read one course's totals for two package sizes solves for both
 * constants, and can then invert any other course's total back to its teacher's
 * approved settlement rate, exactly. Several package sizes make the system
 * overdetermined, so rounding hides nothing.
 *
 * That is why signed-in is not enough, and why the answer is 403 rather than an
 * unpriced or empty list.
 *
 * ⚠️ AND WHY THE TEACHING SIDE IS REFUSED FIRST, BEFORE ANY WAY IN IS ASKED.
 * A stranger's arithmetic stalls at three unknowns and two equations; the
 * teacher's does not, because they already hold their own approved rate — they
 * asked for it and they read it on their statement. Two package sizes hand them
 * the platform's constants by subtraction, and every OTHER teacher's rate
 * follows from any other course's total. FR-021ب forbids a teacher those
 * numbers, and this is the one door neither TeacherFieldAllowlist nor
 * StudentBalanceAllowlist watches: nothing here is a field on a teacher-facing
 * payload — it is the STUDENT'S screen, opened by the wrong person.
 *
 * The refusal OVERRIDES an enrolment rather than merely not being one of the
 * ways in. An enrolment is a row the teacher can cause to exist, so a check
 * that only failed to admit them would be one `Enrollment::create` from being
 * no check at all.
 *
 * Membership is an allowlist on the pivot role — STUDENT and nothing else, so a
 * role added by a later spec cannot buy until somebody says it may. It counts
 * alongside enrolment because it is the FIRST purchase: a student buying credits
 * for their first session has no enrolment yet, and requiring one would leave
 * the packages screen reachable only by people who no longer need it.
 */
class CourseParticipation
{
    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

    public function isPartyTo(User $user, Course $course): bool
    {
        if ($this->isSeller($user, $course)) {
            return false;
        }

        return $this->enrollments->hasActiveEnrollment($user, (int) $course->getKey())
            || $this->enrollments->hasActiveEnrollmentInWorkspace($user, (int) $course->workspace_id)
            || $this->buysIn($user, $course);
    }

    /**
     * On the teaching side of this workspace — owner, teacher or assistant.
     *
     * Asked as "a member whose role is not STUDENT" rather than by naming the
     * three: a denylist of role names lets the next role added ship with the
     * leak open, and this is the predicate a leak would be invisible in.
     *
     * ⚠️ PUBLIC SINCE 024, AND THE REASON IS THE HALF OF `isPartyTo()` IT IS NOT.
     * That method answers TWO questions in one call: this refusal, and then the
     * ways in (an enrolment, a membership). Spec 024 lets a platform officer buy
     * on a student's behalf, and the ways in are exactly what a brand-new student
     * has none of — so the officer skips them. Skipping the whole method would
     * take this refusal with it, and a grant to a course's own teacher hands them
     * two totals on two package sizes, which solve for the platform's constants
     * and then invert every OTHER teacher's approved settlement rate. So the two
     * halves are asked separately now, and this one is asked ALWAYS.
     */
    public function isSeller(User $user, Course $course): bool
    {
        return $user->workspaces()
            ->whereKey($course->workspace_id)
            ->wherePivot('role', '!=', Roles::STUDENT)
            ->exists();
    }

    private function buysIn(User $user, Course $course): bool
    {
        return $user->workspaces()
            ->whereKey($course->workspace_id)
            ->wherePivot('role', Roles::STUDENT)
            ->exists();
    }
}
