<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

/**
 * Turning four component percentages and a set of weights into one grade.
 *
 * ⚠️ A COMPONENT WITH NO DATA IS EXCLUDED AND THE REST ARE RE-WEIGHTED — it is
 * never counted as zero (FR-053). A student who was never set any homework is
 * not a student who scored nothing on it, and treating the two the same drags
 * every such student's grade down by the whole homework weight for a reason
 * nobody can see on the page. It is the same decision as `wrong_pct = NULL` for
 * a question too few people sat, and as `improvement_index` staying null when no
 * teacher rated it.
 *
 * ⚠️ AND THE WEIGHTS ARE COPIED INTO THE RESULT, not referenced. The result is
 * stored on the segment as a snapshot (FR-052): the teacher may reweight
 * tomorrow, and a card the family has already read must not silently become a
 * different document.
 */
class GradeWeighting
{
    /**
     * @param  array<string, float|null>  $measured  component => percentage, null = no data
     * @param  array<string, int>  $weights  component => weight out of 100
     * @return array{components: array<string, array{pct: float, weight: float}>, total: float|null}
     */
    public function apply(array $measured, array $weights): array
    {
        $present = [];

        foreach ($measured as $component => $pct) {
            if ($pct === null) {
                continue;
            }

            $weight = (float) ($weights[$component] ?? 0);

            if ($weight <= 0.0) {
                // Weighted out by the teacher. Excluded from the re-weighting for
                // the same reason a missing component is: showing a row that
                // contributes nothing invites the reader to add it in themselves.
                continue;
            }

            $present[$component] = ['pct' => $pct, 'weight' => $weight];
        }

        $weightTotal = array_sum(array_column($present, 'weight'));

        if ($weightTotal <= 0.0) {
            // Nothing measurable this period. Null, never zero — a card that
            // reports 0% for a student with no data at all is a false statement
            // about them, and it is the one their guardian reads.
            return ['components' => [], 'total' => null];
        }

        $total = 0.0;

        foreach ($present as $component => $row) {
            // The weight AS RE-WEIGHTED, so the page adds up to 100 and the
            // reader can check the arithmetic that produced the grade. Storing
            // the original weight beside a total computed from a different one is
            // how a correct number gets reported as a bug for ever.
            $share = round($row['weight'] / $weightTotal * 100, 2);
            $present[$component]['weight'] = $share;
            $total += $row['pct'] * $row['weight'] / $weightTotal;
        }

        return ['components' => $present, 'total' => round($total, 2)];
    }
}
