<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * "Which of these groups does a live price actually reach?" (٠٣٦ · FR-003 · FR-016).
 *
 * Owned and implemented by `Payments`; asked by `Learning`, which may not name
 * `plans` at all — {@see SubscriptionDirectory} says so in as many words, and
 * this interface is the whole of that crossing for the question of price.
 *
 * ⚠️ BULK BY SIGNATURE, LIKE EVERY LIST-SHAPED READ IN THIS DIRECTORY. A
 * Resource runs once per row, so a per-group question inside one is an N+1 by
 * construction — the `ClassSessionResource` defect arriving through yet another
 * door.
 *
 * ⛔ TRIPLES GO IN, NOT BARE IDS, AND EACH PART OF THEM IS LOAD-BEARING.
 *
 * - The **uuid** is what a narrow plan names: `plans.coverage_uuid` is a `uuid`
 *   column, and the implementer may not turn an id into one because that would
 *   be `Payments` reading `cohorts`. Without it the group arm has nothing to
 *   compare against at all.
 * - The **course id** is the inheritance anchor. A group with no price of its
 *   own is reached by its course's price, and the implementer cannot learn which
 *   course a group belongs to without reading the forbidden table.
 * - The **workspace id** is the tenant pin, and it is not a courtesy. A
 *   workspace-wide plan carries a NULL coverage uuid, so without the pin the
 *   inheritance arm degenerates into «is there ANY live workspace plan on the
 *   platform?» — true for every group of every teacher. That leak is invisible
 *   to any fixture with one workspace in it.
 *
 * ⚠️ AND WHAT IT DOES NOT ANSWER IS HALF THE CONTRACT. No price and no amount
 * (no money reaches a student's or a teacher's screen — two tests in this
 * repository fail the build over it); no plan title and no plan id (a different
 * permission answers those); and above all **no reason**. «Why is it not
 * listed» is the teacher's question alone and belongs to
 * {@see CohortPricingReasonDirectory} — folded in here it would carry a
 * commercial fact between a teacher and the platform into a public read.
 */
interface SellableCohortDirectory
{
    /**
     * The ids of the groups a live price reaches, out of the candidates given.
     *
     * A candidate that is not returned is not listed. Order is not promised and
     * duplicates are not returned; the caller compares against a set.
     *
     * @param  list<array{id: int, uuid: string, course_id: int, workspace_id: int}>  $cohorts
     * @return list<int>
     */
    public function sellableCohortIds(array $cohorts): array;
}
