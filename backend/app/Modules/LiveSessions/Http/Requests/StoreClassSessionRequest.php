<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Requests;

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ⚠️ UUIDS ON THE WIRE, IDS INSIDE — and this request used to take raw
 * autoincrement ids, which is what made `/manage/sessions` unusable.
 *
 * The repository rule is `HasUuid`: routes and API payloads expose `uuid`, never
 * the sequential id. This endpoint broke it, and the cost was not theoretical.
 * The scheduling screen asked an operator to TYPE `teacher_profile_id` into a free
 * text field — a number nobody can know — and disabled both of its buttons until
 * it was filled. The create button was dead for everyone, permanently.
 *
 * A sequential id on the wire is also enumerable: `1, 2, 3…` walks a workspace's
 * teachers and courses, and the 404-vs-422 difference answers which exist.
 *
 * The ids are resolved here in {@see self::payload()} rather than in the DTO,
 * which stays a dumb carrier of integers — so nothing downstream changed, and the
 * Actions and every test that calls them directly are untouched.
 */
class StoreClassSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The policy runs in the controller; keeping it out of here means one
        // place decides, not two that can disagree.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // WorkspaceRules, not `exists:` — Laravel's rule is a raw query that
            // walks straight past the global scope (Constitution I). The column
            // is now `uuid`; the workspace filter is what it always was.
            'teacher_profile_uuid' => ['required', 'uuid', WorkspaceRules::exists('teacher_profiles', 'uuid')],
            // Required since Q-7: the session price is a property of the course,
            // so a session with no course is a session with no price and can
            // never consume a credit. The COLUMN stays nullable for historic
            // rows; the rule is enforced here and in ScheduleClassSession.
            'course_uuid' => ['required', 'uuid', WorkspaceRules::exists('courses', 'uuid')],
            /*
            | ⚠️ A PLAIN `exists`, DELIBERATELY — and it is the one place in this
            | file where `WorkspaceRules` would be WRONG. Since spec 009 `subjects`
            | is PLATFORM reference data with no `workspace_id` column at all, so a
            | workspace-scoped rule would build a query against a column that does
            | not exist. It was previously `['nullable', 'integer']` — no existence
            | check of any kind.
            */
            'subject_uuid' => ['nullable', 'uuid', Rule::exists('subjects', 'uuid')],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(ClassSessionType::class)],
            'starts_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'seats_total' => ['required', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * The validated payload with each uuid resolved to its id.
     *
     * ⚠️ RESOLVED HERE, NOT IN THE DTO. `ScheduleSessionData` is a dumb carrier of
     * integers that seeders, Filament and every Action test construct directly —
     * teaching it to run database lookups would put a query inside a value object
     * and change the shape of forty call sites that never touch HTTP.
     *
     * The rules above already proved each row exists AND is in this workspace, so
     * these lookups cannot miss; `firstOrFail` is the honest spelling of that
     * rather than a silent null.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        $data['teacher_profile_id'] = TeacherProfile::query()
            ->where('uuid', $data['teacher_profile_uuid'])
            ->firstOrFail()
            ->getKey();

        $data['course_id'] = Course::query()
            ->where('uuid', $data['course_uuid'])
            ->firstOrFail()
            ->getKey();

        if (($data['subject_uuid'] ?? null) !== null) {
            $data['subject_id'] = Subject::query()
                ->where('uuid', $data['subject_uuid'])
                ->firstOrFail()
                ->getKey();
        }

        return $data;
    }
}
