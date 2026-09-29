<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One handed-in paper on the staff list (`GET /manage/attempts`).
 *
 * ⚠️ THE STUDENT KEY IS ABSENT, NOT NULL, WHEN GRADING IS ANONYMOUS (FR-033) —
 * the same rule as {@see GradingQueueResource}. The rows here include the
 * grading queue's own papers, so a name on this list beside an exam and a
 * submission time would un-hide the board by cross-reference. `whenLoaded` is
 * the switch: the Action does not load the relation at all when names are off.
 *
 * ⚠️ AND THE SCORE ONLY WHEN THE PAPER IS MARKED. A `pending_grading` paper's
 * `score` is the machine-marked half alone; presented bare it reads as the
 * student's result, which is exactly what it is not.
 *
 * @mixin Attempt
 */
class StaffAttemptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $graded = $this->status === Attempt::STATUS_GRADED;
        $exam = $this->exam;
        $course = $exam?->course;
        $max = (float) $this->max_score;

        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'student' => $this->whenLoaded('student', fn (): ?array => $this->student instanceof User ? [
                'uuid' => $this->student->uuid,
                'name' => $this->student->name,
            ] : null),
            'exam' => $exam === null ? null : ['uuid' => $exam->uuid, 'title' => $exam->title],
            'course' => $course === null ? null : ['uuid' => $course->uuid, 'title' => $course->title],
            'score' => $graded ? (float) $this->score : null,
            'max_score' => $graded ? $max : null,
            'percentage' => $graded && $max > 0 ? round((float) $this->score / $max * 100, 1) : null,
            'passed' => $graded ? (bool) $this->passed : null,
            'submitted_at' => $this->submitted_at,
        ];
    }
}
