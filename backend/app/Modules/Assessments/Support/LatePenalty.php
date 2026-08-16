<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Models\Assignment;

/**
 * What being late costs (FR-046 · FR-046أ).
 *
 * ⚠️ THE CAP AND THE FLOOR ARE BOTH ENFORCED HERE, and neither is decoration.
 * Ten days at 20٪ a day is −100٪: a submission worth MINUS its own marks, which
 * then drags down every other mark it is summed with. A teacher who typed 20 and
 * left the cap alone would have written that, and would find out from a student
 * whose total went down after handing work in.
 *
 * ⚠️ AND A DAY BEGUN COUNTS WHOLE (Q6). One minute past midnight is a day late,
 * because the alternative — charging by the hour — makes the penalty a number
 * neither the teacher nor the student can predict before they hand in, and the
 * point of a stated policy is that it can be read in advance.
 */
class LatePenalty
{
    private const MINUTES_PER_DAY = 1440;

    /** Whole days late, where zero minutes is zero days. */
    public function daysLate(int $lateByMinutes): int
    {
        return $lateByMinutes <= 0 ? 0 : (int) ceil($lateByMinutes / self::MINUTES_PER_DAY);
    }

    /**
     * The percentage to knock off, capped by the assignment's own ceiling.
     *
     * A policy of `accept` charges nothing however late; `reject` never reaches
     * here, because the hand-in is refused before a penalty could apply.
     */
    public function percentFor(Assignment $assignment, int $lateByMinutes): float
    {
        if ($assignment->late_policy !== Assignment::LATE_PENALTY) {
            return 0.0;
        }

        $perDay = (float) $assignment->late_penalty_pct_per_day;
        $cap = (float) $assignment->late_penalty_cap_pct;

        return min(max($cap, 0.0), $perDay * $this->daysLate($lateByMinutes), 100.0);
    }

    /**
     * The mark after the penalty, never below zero.
     *
     * The floor is here as well as in the cap because the two answer different
     * questions: the cap bounds the POLICY, the floor bounds the RESULT. A cap of
     * 100٪ is a legitimate policy — everything forfeited — and it must produce a
     * zero rather than a rounding artefact below it.
     */
    public function apply(float $rawScore, float $penaltyPct): float
    {
        return max(0.0, round($rawScore * (1 - $penaltyPct / 100), 2));
    }
}
