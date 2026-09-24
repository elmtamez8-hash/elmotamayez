<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use App\Modules\Assessments\Models\Assignment;
use App\Modules\Courses\Models\Course;
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
            /*
            | ⚠️ THE COURSE ARRIVES AS A UUID, NEVER AS THE ROW ID. This was
            | `course_id` — an autoincrement id no payload in the product exposes,
            | so the only screen that could ever call this route had no value to
            | send and the endpoint sat without a caller. Resolved to the id in
            | `actionData()` below, so `SaveAssignment` keeps its id contract for
            | the seeders and the panel.
            */
            'course_uuid' => ['nullable', 'string', WorkspaceRules::exists('courses', 'uuid')],
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

    /**
     * What the Action receives: the validated fields, the course resolved to its
     * id, and — on an edit — every link the form does not carry left as it was.
     *
     * ⚠️ `SaveAssignment` REPLACES rather than merges: a key that is absent is
     * written as null. The form on `/manage/assignments` carries no lesson and no
     * session, so without this an edit of homework a seeder or the panel attached
     * to a session would silently detach it from that session.
     *
     * @return array<string, mixed>
     */
    public function actionData(?Assignment $existing = null): array
    {
        $data = $this->validated();

        if (array_key_exists('course_uuid', $data)) {
            $uuid = $data['course_uuid'];
            unset($data['course_uuid']);

            // The scope bites here (a teacher's own request), and the rule above
            // has already refused anything outside this workspace.
            $data['course_id'] = $uuid === null
                ? null
                : Course::query()->where('uuid', $uuid)->value('id');
        } elseif ($existing !== null) {
            $data['course_id'] = $existing->course_id;
        }

        if ($existing !== null) {
            foreach (['lesson_id', 'class_session_id'] as $key) {
                if (! array_key_exists($key, $data)) {
                    $data[$key] = $existing->getAttribute($key);
                }
            }
        }

        return $data;
    }
}
