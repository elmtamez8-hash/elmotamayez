<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Requests;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // walks straight past the global scope (Constitution I).
            'teacher_profile_id' => ['required', WorkspaceRules::exists('teacher_profiles')],
            // Required since Q-7: the session price is a property of the course,
            // so a session with no course is a session with no price and can
            // never consume a credit. The COLUMN stays nullable for historic
            // rows; the rule is enforced here and in ScheduleClassSession.
            'course_id' => ['required', WorkspaceRules::exists('courses')],
            'subject_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(ClassSessionType::class)],
            'starts_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'seats_total' => ['required', 'integer', 'min:1', 'max:500'],
        ];
    }
}
