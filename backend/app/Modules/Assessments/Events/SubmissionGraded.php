<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Events;

use App\Modules\Assessments\Models\Submission;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A hand-in has a mark on it, and the mark is final (FR-050).
 *
 * Distinct from `AssignmentSubmitted` for the reason `SessionDelivered` is
 * distinct from `SessionCompleted`: one flag on one event makes the difference
 * optional for every listener, and the listener that must not treat it as
 * optional is the one that tells the student their result.
 */
class SubmissionGraded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Submission $submission,
    ) {}
}
