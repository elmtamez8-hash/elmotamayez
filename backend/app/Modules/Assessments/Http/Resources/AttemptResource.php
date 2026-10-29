<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Models\Attempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attempt
 */
class AttemptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'score' => (float) $this->score,
            'max_score' => (float) $this->max_score,
            'passed' => $this->passed,
            /*
             | ⚠️ THE DURATION THIS STUDENT WAS GRANTED, AND THIS IS ITS ONLY
             | READER. It is frozen on the attempt at start (FR-054), so an
             | accommodation withdrawn afterwards does not re-time a paper
             | already sat — and without this line the column existed, was
             | written correctly, and reached nobody. Since 2026-10-29 the SERVER
             | enforces it (`Attempt::deadline()`), and `deadline_at` below is
             | that same instant — the screen counts down to it rather than
             | re-adding the minutes to its own clock.
             |
             | On the student's own attempt payload rather than on ExamResource,
             | which every reader shares: a shared object that quietly differed
             | per reader would announce that the accommodation exists (FR-056).
             */
            'duration_minutes' => $this->duration_minutes,
            'deadline_at' => $this->resource->deadline()?->toIso8601String(),
            /*
            | ⚠️ AND HOW MANY SECONDS ARE LEFT, MEASURED HERE. The screen counts
            | down from THIS, anchored to the moment the response arrives — never
            | to `deadline_at` read against the device's own clock: a phone five
            | minutes fast would have handed the paper in five minutes early.
            */
            'seconds_left' => ($deadline = $this->resource->deadline()) === null
                ? null
                : max(0, $deadline->getTimestamp() - now()->getTimestamp()),
            // The answers saved so far, so a reload resumes the paper as it
            // was. While the sitting is open only; afterwards `answers` is the
            // record and this is spent.
            'draft_answers' => $this->status === Attempt::STATUS_IN_PROGRESS
                ? GradeAttempt::draftOf($this->resource)
                : null,
            'started_at' => $this->started_at,
            'submitted_at' => $this->submitted_at,
        ];
    }
}
