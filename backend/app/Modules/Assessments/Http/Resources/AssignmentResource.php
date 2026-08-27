<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Assignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One piece of homework, as the class sees it.
 *
 * ⚠️ `due_at` HERE IS THE ASSIGNMENT'S OWN DATE, not anybody's effective one.
 * The student's deadline — moved by an accommodation or an extension — belongs
 * to their own submission payload, because a shared object that quietly differs
 * per reader is how the existence of an accommodation leaks (FR-056).
 *
 * @mixin Assignment
 */
class AssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'points' => $this->points,
            'due_at' => $this->due_at,
            /*
            | ⚠️ WHICH SUBJECT, AND WITH WHOM. The list is one page across every
            | teacher a student studies with — `StudentScope` widened it there in
            | 008 — and until now every row said only its own title, so «واجب
            | الفصل الثالث» from one teacher sat above the identically-named one
            | from another with nothing between them. It is also what the filter
            | bar narrows by, and a control that filters on a field the card does
            | not show is a control whose effect cannot be read.
            |
            | `whenLoaded`, so the teacher's own list — which does not eager-load
            | it — pays nothing and sends nothing rather than an N+1 per row.
            */
            'course' => $this->whenLoaded('course', fn (): ?array => $this->course === null ? null : [
                'uuid' => (string) $this->course->uuid,
                'title' => (string) $this->course->title,
            ]),
            'teacher' => $this->whenLoaded('workspace', fn (): ?array => $this->workspace === null ? null : [
                'uuid' => (string) $this->workspace->uuid,
                'name' => (string) $this->workspace->name,
            ]),
            'submission_type' => $this->submission_type,
            'late_policy' => $this->late_policy,
            'late_penalty_pct_per_day' => (float) $this->late_penalty_pct_per_day,
            'late_penalty_cap_pct' => (float) $this->late_penalty_cap_pct,
            'status' => $this->status,
            'published_at' => $this->published_at,
            // Counted in the query, never here: a Resource runs once per row.
            'submitted_count' => $this->getAttribute('submitted_count') === null
                ? null
                : (int) $this->getAttribute('submitted_count'),
            'pending_count' => $this->getAttribute('pending_count') === null
                ? null
                : (int) $this->getAttribute('pending_count'),
            // The student's own row, attached by the controller when there is one.
            'my_submission' => $this->relationLoaded('submissions') && $this->submissions->isNotEmpty()
                ? SubmissionResource::make($this->submissions->first())
                : null,
        ];
    }
}
