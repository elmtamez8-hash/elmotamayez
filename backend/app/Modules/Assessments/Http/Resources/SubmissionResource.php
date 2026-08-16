<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Submission;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One hand-in.
 *
 * ⚠️ `state`, `submitted_at` AND `extension_until` BELONG TO THE ROW'S OWNER AND
 * TO WHOEVER MARKS IT — AND TO NOBODY ELSE (FR-056). A submission timestamped
 * after the deadline and labelled «في الموعد» tells any reader who can subtract
 * that its owner had an extension, which is the existence of an accommodation
 * announced by arithmetic rather than by a field. Omitting only
 * `extension_until` would not close it: the pair of the other two says the same
 * thing.
 *
 * Absent keys rather than nulls, on the grading queue's precedent: a client
 * cannot render a redaction where a value used to be if there is no key to
 * render.
 *
 * @mixin Submission
 */
class SubmissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $reader = $request->user();

        $isPrivileged = $reader !== null && (
            $this->student_user_id === $reader->getKey()
            || $reader->can(Permissions::SUBMISSIONS_GRADE)
        );

        return [
            'uuid' => $this->uuid,
            'assignment' => $this->relationLoaded('assignment') && $this->assignment !== null
                ? ['uuid' => $this->assignment->uuid, 'title' => $this->assignment->title, 'points' => $this->assignment->points]
                : null,
            'student' => $this->relationLoaded('student') && $this->student !== null
                ? ['uuid' => $this->student->uuid, 'name' => $this->student->name]
                : null,
            'answer_text' => $isPrivileged ? $this->answer_text : null,
            'has_file' => $this->file() !== null,
            'score' => $this->score === null ? null : (float) $this->score,
            'feedback' => $this->feedback,
            'graded_at' => $this->graded_at,
            'is_graded' => $this->isGraded(),
            // How much was knocked off, and why the mark is lower than the work.
            // Shown to the owner because a reduction with no stated cause is the
            // one they write to their teacher about.
            'late_penalty_applied_pct' => $isPrivileged ? (float) $this->late_penalty_applied_pct : null,
            ...($isPrivileged ? [
                'state' => $this->state,
                'submitted_at' => $this->submitted_at,
                'late_by_minutes' => (int) $this->late_by_minutes,
                'extension_until' => $this->extension_until,
            ] : []),
        ];
    }
}
