<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Events;

use App\Modules\Assessments\Models\Submission;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A student handed work in.
 *
 * Carries the submission rather than the assignment and the student separately:
 * the state and the lateness were decided at that moment and are on the row, and
 * a listener that recomputed them from the assignment would get a different
 * answer the day the policy changed.
 */
class AssignmentSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Submission $submission,
    ) {}
}
