<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Events;

use App\Modules\Assessments\Models\Attempt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An attempt was submitted and is waiting on a person to read an essay.
 *
 * Distinct from `ExamSubmitted` for the same reason `SessionDelivered` is
 * distinct from `SessionCompleted` in spec 005: one flag on one event makes the
 * condition optional for every listener, and the listener that must not treat it
 * as optional is the one that issues certificates.
 */
class AttemptPendingGrading
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Attempt $attempt,
    ) {}
}
