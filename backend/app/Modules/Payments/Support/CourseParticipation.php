<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

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
    /**
     * ⚠️ ONE SPELLING, BECAUSE `CreditPurchaseController::courseFor()` ANSWERS A
     * MISSING COURSE WITH IT. That equality is what stops the pair of doors being
     * an existence oracle, and two hand-typed Arabic sentences drift by one
     * character without anything failing.
     */
    public const NOT_A_PARTY = 'لا يمكنك شراء أرصدة على كورس لست طرفاً فيه.';

    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

    /**
     * ⚠️ THE REFUSAL EVERY CREDIT DOOR ASKS — AND THE ONE PLACE THE CAPACITY IS
     * DERIVED. Both doors (`ListCreditPackages`, `PurchaseCredits`) carried their
     * own copy of a two-branch condition; a third caller would have been a third
     * copy, and the copies had already started to differ in what they asked.
     *
     * Three capacities, and the capacity is read off the PROXY'S OWN PERMISSION,
     * never off a field in the request — a request that could name its own
     * capacity is a licence the caller writes for themself.
     *
     * | who | how it is known | what is proved |
     * |---|---|---|
     * | the student, for themself | no proxy at all | `isPartyTo($student)` |
     * | a guardian | a proxy WITHOUT the platform's financial permission | `isPartyTo($student)` **and** the guardian is not the seller |
     * | a platform officer | a proxy WITH it | the seller refusal alone, as since 024 |
     *
     * ⚠️ AND THE ORDER IS PART OF THE RULE: `$grantedBy === null` is answered
     * FIRST, above any `can()`. An officer buying for themself is a student like
     * anyone else, and asking their permission first would let them skip the
     * participation condition on their own purchase.
     *
     * ⚠️ THE GUARDIAN'S OWN SELLER REFUSAL IS THE HALF THAT IS EASY TO MISS.
     * A teacher who is also somebody's parent, whose child is enrolled in that
     * teacher's own course, would otherwise read their own course's totals for
     * two package sizes THROUGH THE CHILD — which solve for the platform's two
     * constants and then invert every other teacher's approved settlement rate
     * (FR-021ب). It is the exact leak this class exists to close, reached by a
     * road `isPartyTo($student)` cannot see, because the person being checked is
     * not the person reading the screen.
     *
     * The guardian's refusal borrows the GENERAL sentence rather than the
     * officer's «لمن يدرّس هذا الكورس»: that one is written for an admin screen,
     * and showing it here would split «you are on the teaching side of this
     * workspace» from «you are not a party», which are one answer today.
     *
     * @throws AuthorizationException
     */
    public function mayBuyFor(?User $grantedBy, User $student, Course $course): void
    {
        if ($grantedBy === null) {
            if (! $this->isPartyTo($student, $course)) {
                throw new AuthorizationException(self::NOT_A_PARTY);
            }

            return;
        }

        if ($grantedBy->can(Permissions::BILLING_PURCHASE_APPROVE)) {
            /*
            | The officer's grant, unchanged since 024: the ways in are exactly
            | what a brand-new student has none of, so they are skipped — and the
            | seller refusal is not, because it is the one that costs money.
            */
            if ($this->isSeller($student, $course)) {
                throw new AuthorizationException('لا يمكن منح أرصدة لمن يدرّس هذا الكورس.');
            }

            return;
        }

        if (! $this->isPartyTo($student, $course) || $this->isSeller($grantedBy, $course)) {
            throw new AuthorizationException(self::NOT_A_PARTY);
        }
    }

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

    /**
     * The plural of {@see self::isPartyTo()} — every course this student may have
     * credits bought on, as a query.
     *
     * ⚠️ **A SET, NOT A LOOP.** Filtering a catalogue through `isPartyTo()` costs
     * three queries per course, which is the N+1 defect this tree has paid for
     * twice, moved into `Support/` where nothing measures it. The three ways in
     * are three id lists and the two refusals are two more, so the whole answer
     * is FIVE constant queries followed by one — however many courses exist.
     *
     * ⚠️ **AND THE SELLER REFUSAL SUBTRACTS, IT DOES NOT MERELY FAIL TO ADD.**
     * `isPartyTo()` refuses the teaching side ABOVE every way in — an enrolment is
     * a row a teacher can cause to exist, so a set that only failed to include
     * them would be one `Enrollment::create` from being no refusal at all.
     *
     * ⚠️ **THE PAYER IS IN THE SIGNATURE FOR THE SAME REASON `mayBuyFor()` ASKS
     * ABOUT THEM.** A teacher who is also a parent must not be offered their own
     * course through their child and then refused at the door — two answers to
     * one question, which is the defect `BookingEligibility` and
     * `ListLeaderboardScopes` have each already paid for.
     *
     * ⚠️ **`withoutWorkspaceScope()`, AND WITHOUT IT A GUARDIAN WHO OWNS A
     * WORKSPACE READS THEIR OWN.** `Course` carries `BelongsToWorkspace`, and
     * `WorkspaceContext::id()` falls back to `users.last_workspace_id` for
     * everybody — so a parent who also teaches would have this list ANDed with
     * their own workspace id and see their own catalogue. The replacement guard is
     * not weaker: `whereIn('workspace_id', …)` derived from the sets is what the
     * scope was standing in for, spelled out where it can be read.
     *
     * @return Builder<Course>
     */
    public function coursesOpenTo(User $student, ?User $payer = null): Builder
    {
        $enrolledCourseIds = $this->enrollments->activeCourseIdsFor($student);
        $enrolledWorkspaceIds = $this->enrollments->activeWorkspaceIdsFor($student);
        $buysInWorkspaceIds = $this->workspaceIdsWhereRole($student, '=');

        $sellsInWorkspaceIds = $this->workspaceIdsWhereRole($student, '!=');

        if ($payer !== null) {
            $sellsInWorkspaceIds = array_values(array_unique(array_merge(
                $sellsInWorkspaceIds,
                $this->workspaceIdsWhereRole($payer, '!='),
            )));
        }

        $openWorkspaceIds = array_values(array_unique(array_merge($enrolledWorkspaceIds, $buysInWorkspaceIds)));

        return Course::query()
            ->withoutWorkspaceScope()
            ->where(function (Builder $query) use ($enrolledCourseIds, $openWorkspaceIds): void {
                $query->whereIn('id', $enrolledCourseIds)
                    ->orWhereIn('workspace_id', $openWorkspaceIds);
            })
            ->whereNotIn('workspace_id', $sellsInWorkspaceIds);
    }

    /**
     * The workspaces this person is a member of, on one side of the STUDENT role.
     *
     * `'='` is the buyer side and `'!='` the teaching side — the same allowlist /
     * denylist split {@see self::buysIn()} and {@see self::isSeller()} make one
     * course at a time, so the two spellings cannot answer differently.
     *
     * @return list<int>
     */
    private function workspaceIdsWhereRole(User $user, string $operator): array
    {
        $ids = $user->workspaces()
            ->wherePivot('role', $operator, Roles::STUDENT)
            ->pluck('workspaces.id')
            ->all();

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }

    private function buysIn(User $user, Course $course): bool
    {
        return $user->workspaces()
            ->whereKey($course->workspace_id)
            ->wherePivot('role', Roles::STUDENT)
            ->exists();
    }
}
