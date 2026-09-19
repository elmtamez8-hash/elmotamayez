<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Modules\Learning\Models\Cohort;
use App\Shared\Contracts\SellableCohortDirectory;

/**
 * Carries «a live price reaches this group» onto the rows, in bulk (٠٣٦ · T046).
 *
 * ⛔ ONE SPELLING, BECAUSE THE STAMP HAS SEVERAL STAMPERS. The public read, the
 * student's picker, the teacher's list and the single-row responses of the
 * management screens all have to answer `is_joinable`, and
 * {@see Cohort::priceReaches()} raises rather than falling back to a query — so
 * every one of them stamps. Written out at each site, the triples would be built
 * four times and the day somebody forgets the workspace in one of them, that
 * screen starts reading another teacher's prices.
 *
 * ⚠️ BULK BY SIGNATURE. A Resource runs once per row; asked per cohort this is
 * an N+1 by construction, which is the `ClassSessionResource` defect reached
 * through yet another door.
 *
 * ⚠️ AND EVERY ROW HANDED IN IS STAMPED, INCLUDING ONE NO PRICE REACHES. A row
 * left unstamped throws on its next read ({@see Cohort::priceReaches()}), so
 * «skip the ones that are not listed» would turn an ordinary unlisted group into
 * a 500 on the screen that was meant to leave it out.
 */
class CohortPricing
{
    public function __construct(private readonly SellableCohortDirectory $sellable) {}

    /**
     * Stamp every one of these groups. Rows already stamped are re-stamped; the
     * answer is a fact about right now, not a cache.
     *
     * @param  iterable<int, Cohort>  $cohorts
     * @return iterable<int, Cohort> the same rows, stamped
     */
    public function stamp(iterable $cohorts): iterable
    {
        $candidates = [];

        foreach ($cohorts as $cohort) {
            $candidates[] = [
                'id' => (int) $cohort->getKey(),
                'uuid' => (string) $cohort->uuid,
                'course_id' => (int) $cohort->course_id,
                'workspace_id' => (int) $cohort->workspace_id,
            ];
        }

        $listed = $candidates === [] ? [] : array_flip($this->sellable->sellableCohortIds($candidates));

        foreach ($cohorts as $cohort) {
            $cohort->stampPriceReach(isset($listed[(int) $cohort->getKey()]));
        }

        return $cohorts;
    }

    /** The single-row form, for a response that carries exactly one group. */
    public function stampOne(Cohort $cohort): Cohort
    {
        $this->stamp([$cohort]);

        return $cohort;
    }
}
