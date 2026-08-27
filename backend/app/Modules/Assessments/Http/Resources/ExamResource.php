<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Exam */
class ExamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'passing_score' => $this->passing_score,
            'max_attempts' => $this->max_attempts,
            'shuffle_questions' => $this->shuffle_questions,
            'shuffle_answers' => $this->shuffle_answers,
            'status' => $this->status,
            'is_published' => $this->isPublished(),
            'questions_count' => $this->whenCounted('questions'),
            /*
            | The reader's own record on this paper — FR-017 asks the course tab
            | to show results beside each exam rather than sending the student to
            | another screen to find out whether they passed.
            |
            | ⚠️ `whenLoaded`, SO ITS ABSENCE MEANS «NOT ASKED» RATHER THAN «NONE».
            | A caller that did not constrain the relation to one reader must not
            | have its answer rendered as that reader's score. And the relation is
            | narrowed at the call site to non-practice, submitted attempts: a
            | practice run is the student marking themselves, and counting it here
            | would report a rehearsal as a result.
            */
            'my_attempts' => $this->whenLoaded('attempts', fn (): array => $this->attemptSummary()),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * ⚠️ TWO QUESTIONS OVER ONE SET, AND THEY ARE NARROWED DIFFERENTLY.
     *
     * `count` is what `StartAttempt::guardAttemptLimit()` counts — every
     * non-practice sitting, submitted or not, because an abandoned one has spent
     * a chance. A count that skipped it would tell a student they have a
     * attempt left beside a button the server refuses, which is the two-answers
     * defect this whole screen exists to end.
     *
     * The SCORE reads submitted rows alone: an attempt still open has a null
     * score, and `max()` over it would report a paper in progress as a 0%.
     *
     * @return array{count: int, best_score: float|null, passed: bool, last_uuid: string|null}
     */
    private function attemptSummary(): array
    {
        $attempts = $this->attempts;
        $finished = $attempts->filter(fn ($attempt): bool => $attempt->submitted_at !== null);

        return [
            'count' => $attempts->count(),
            // Null rather than zero for «never sat»: a 0% reads as a paper failed
            // outright, which is the harsher of the two and the wrong one.
            'best_score' => $finished->isEmpty() ? null : (float) $finished->max('score'),
            'passed' => $finished->contains(fn ($attempt): bool => (bool) $attempt->passed),
            'last_uuid' => $finished->sortBy('submitted_at')->last()?->uuid,
        ];
    }
}
