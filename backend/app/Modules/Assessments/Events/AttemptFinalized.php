<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Events;

use App\Modules\Assessments\Models\Attempt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The score is final: every question is marked, by machine or by a person.
 *
 * Fires for practice attempts too — spec 009 counts "solved ten questions on
 * your own" and would otherwise wait for an event that never arrives. What a
 * practice attempt does NOT emit is `ExamPassed`, which is the certificate
 * contract and belongs to a sitting rather than to revision.
 */
class AttemptFinalized
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Attempt $attempt,
    ) {}
}
