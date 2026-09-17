<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\Support\CohortPricingGap;

/**
 * "Why is this group of mine not listed?" — the TEACHER's question (٠٣٦ · FR-014).
 *
 * Owned and implemented by `Payments`; asked by `Learning`, and asked from the
 * teacher's path ALONE.
 *
 * ⛔ A SECOND CONTRACT RATHER THAN A SECOND RETURN VALUE ON THE FIRST, AND THE
 * SEPARATION IS THE POINT. {@see SellableCohortDirectory} is read on a public
 * page; whether the platform has priced a teacher's plan is a commercial fact
 * between that teacher and the platform. One method answering both questions is
 * one forgotten branch away from putting the second on a guest's screen — the
 * shape this repository already records for the settlement audit, which filters
 * by asking for its own subject types rather than by dropping rows afterwards.
 *
 * ⚠️ BULK BY SIGNATURE, for the reason every other list-shaped read here is: the
 * caller is a screen listing every group of a course, and a per-row question
 * inside a Resource is an N+1 by construction.
 *
 * ⚠️ AND A GROUP THAT IS LISTED IS SIMPLY ABSENT FROM THE ANSWER. «No gap» is
 * not a fourth case — a case meaning «nothing is wrong» is a value every caller
 * has to remember to exclude before rendering, and the first one who forgets
 * prints «لا توجد باقة» beside a group that is on sale.
 */
interface CohortPricingReasonDirectory
{
    /**
     * Why each of these groups is not listed, keyed by cohort id.
     *
     * The candidates take the same shape as
     * {@see SellableCohortDirectory::sellableCohortIds()} and for the same
     * reasons — the uuid is what a narrow plan names, the course is the
     * inheritance anchor, and the workspace is the tenant pin without which the
     * inheritance arm answers about every teacher on the platform.
     *
     * @param  list<array{id: int, uuid: string, course_id: int, workspace_id: int}>  $cohorts
     * @return array<int, CohortPricingGap> keyed by cohort id; a group that IS
     *                                      listed is absent
     */
    public function pricingGapsFor(array $cohorts): array;
}
