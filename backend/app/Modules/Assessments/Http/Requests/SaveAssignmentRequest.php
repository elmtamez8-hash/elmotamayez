<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a piece of homework is (FR-043).
 *
 * ⚠️ `WorkspaceRules::exists()`, NEVER `exists:courses,id`. Laravel's rule is a
 * raw query with no global scope, so a bare `exists` would let a teacher attach
 * their homework to another workspace's course — and confirm that course exists
 * while doing it.
 */
class SaveAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::ASSIGNMENTS_MANAGE) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'points' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'due_at' => ['nullable', 'date'],
            'course_id' => ['nullable', WorkspaceRules::exists('courses')],
            'lesson_id' => ['nullable', WorkspaceRules::exists('lessons')],
            'class_session_id' => ['nullable', WorkspaceRules::exists('class_sessions')],
            // `questions` is deliberately absent: FR-044's third leg has no flow
            // behind it yet, and SaveAssignment refuses it for the same reason.
            'submission_type' => ['nullable', 'string', 'in:text,file'],
            'late_policy' => ['nullable', 'string', 'in:accept,reject,penalty'],
            'late_penalty_pct_per_day' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // ⚠️ The cap is bounded here AND in the Action. Without one, ten days
            // at 20٪ is −100٪ — a submission worth minus its own marks (FR-046أ).
            'late_penalty_cap_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
