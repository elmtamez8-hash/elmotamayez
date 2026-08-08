<?php

declare(strict_types=1);

namespace App\Modules\Courses\Enums;

/**
 * What an exam item asks of a student before the course goes on (FR-041).
 *
 * Two values and no third. A percentage typed here would be a second passing
 * mark beside the exam's own, free to disagree with it — so "must pass" means
 * the exam's `passing_score`, read from the exam, and there is nowhere to type a
 * different number.
 *
 * `Attempt` is the default deliberately: it is the weaker gate. A column that
 * defaulted to `Pass` would silently lock every student who fails one quiz out
 * of the rest of a course their teacher never meant to gate.
 */
enum ExamGate: string
{
    /** Sitting it is enough — the mark is feedback, not a door. */
    case Attempt = 'attempt';

    /** The exam's own passing score must be reached. */
    case Pass = 'pass';

    public function label(): string
    {
        return match ($this) {
            self::Attempt => 'يكفي أن يُحاول',
            self::Pass => 'يجب أن ينجح',
        };
    }
}
