<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use DateTimeInterface;

/**
 * What the subscription layer answers to the rest of the product (spec 027).
 *
 * Implemented by `Payments\Support\SubscriptionEligibility`; asked by LiveSessions
 * (the automatic seat claim) and by Learning (the self-enrolment door). Neither
 * module may import a Payments class, and neither may name the `subscriptions` or
 * `plans` tables — this interface is the whole of the crossing.
 *
 * ⚠️ THE FIRST METHOD'S SIGNATURE IS THE THIRD ONE DRAWN, AND EACH PARAMETER IS
 * A DEFECT THAT WAS MEASURED RATHER THAN IMAGINED:
 *
 *  1. IT IS NOT CALLED `coversCourse`. The implementer already declares
 *     `coversCourse(int $studentUserId, int $courseId, ?DateTimeInterface): bool`
 *     with a live caller in `EloquentAccountStanding`. An interface method of the
 *     same name and a different signature is a fatal error at `implements`, and
 *     renaming the existing one breaks its caller.
 *
 *  2. THE MOMENT IS A PARAMETER, NOT `now()`. Every subscription read in this
 *     tree is moment-bound, and `coveringSession()` says why: «a subscription
 *     that ran out overnight still paid for yesterday's lesson». Without it, a
 *     teacher scheduling in September for October books a student whose window
 *     closes in between — and the charge path, which DOES ask the moment, then
 *     debits a credit for the seat the platform booked on their behalf. The
 *     cancelled row survives on the unique index, so there is no way back.
 *
 *  3. THE CANDIDATE LIST COMES IN; IT IS NOT BUILT HERE. A session belongs to a
 *     cohort and a subscription does not, so «all subscribers of this course»
 *     books the Saturday group's students into the Sunday group's room — the
 *     thing spec 021 is built to prevent. Callers start from
 *     `CohortDirectory::activeMemberIdsFor()` and narrow with this. Asked the
 *     other way round, a workspace-coverage plan returns a subscriber who is in
 *     no cohort of this course at all.
 *
 * ⚠️ AND IT IS BULK BY SIGNATURE, for the reason `CohortDirectory` writes down:
 * a per-row read is an N+1 by construction wherever a list is involved.
 */
interface SubscriptionDirectory
{
    /**
     * Which of these students held a live subscription covering this course, with
     * this session type, at this moment.
     *
     * @param  list<int>  $studentUserIds
     * @return list<int> the subset that did
     */
    public function subscriberIdsAmong(
        array $studentUserIds,
        int $courseId,
        string $sessionType,
        DateTimeInterface $moment,
    ): array;

    /**
     * Whether this course is sold — by a one-off price or by any sellable plan
     * that reaches it (spec 027 · FR-004).
     *
     * ⚠️ THIS EXISTS BECAUSE `Course::isFree()` CANNOT ANSWER IT. That method is
     * `price_minor === 0`, and `courses.price` defaults to 0 and prices the
     * ONE-OFF purchase alone — so a course sold by subscription or by credits
     * reads as free, and narrowing the self-enrolment door with it leaves that
     * door open for exactly the courses the feature exists to sell, while the
     * criterion that measures the narrowing passes green over it.
     */
    public function courseRequiresPurchase(int $courseId): bool;

    /**
     * Whether a sellable plan reaches this course — optionally of one session
     * type only (spec 027 · FR-003).
     *
     * ⚠️ ONE BOOLEAN, AND THAT IS THE POINT. `PurchaseSubscription` collapses
     * «no plan», «switched off» and «priced by nobody yet» into a single Arabic
     * sentence on purpose, so that nothing tells a stranger which teachers have
     * a plan awaiting a price. This answers the same three cases with the same
     * one value, which is why it is safe on a payload every internet visitor
     * reads.
     *
     * The public course page needs it because the private-subscription
     * invitation may only appear when there is something to buy — and plans sit
     * behind `auth:sanctum` while that page is anonymous and server-rendered. A
     * button that is pressed and then refused is the defect spec 023 wrote its
     * refusal branch to avoid.
     */
    public function hasSellablePlanFor(int $courseId, ?string $sessionType = null): bool;
}
