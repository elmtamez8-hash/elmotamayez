<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

/**
 * What happens to a submission that arrives after the deadline (FR-046).
 *
 * `Penalise` carries two numbers on the assignment — a percentage per day and a
 * cap — and the cap is not optional politeness. Without it, twenty percent a day
 * against a submission ten days late computes to minus one hundred percent, and
 * a student who handed in late work would owe marks. The floor of zero and the
 * cap are both enforced in the Action (FR-046أ); no column guards them.
 *
 * A flat one-off deduction is this case with the cap set equal to the daily
 * rate, which is why there is no fourth member for it.
 */
enum LatePolicy: string
{
    case Accept = 'accept';
    case Reject = 'reject';
    case Penalise = 'penalise';
}
