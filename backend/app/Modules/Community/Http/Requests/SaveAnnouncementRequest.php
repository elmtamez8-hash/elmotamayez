<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Requests;

use App\Modules\Community\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAnnouncementRequest extends FormRequest
{
    /** Authorisation is the policy, applied in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:2', 'max:2000'],
            'scope' => ['required', Rule::in([
                Announcement::SCOPE_ALL,
                Announcement::SCOPE_COURSE,
                Announcement::SCOPE_SESSION,
                Announcement::SCOPE_COHORT,
            ])],
            /*
            | ⚠️ A UUID AND NOT AN `exists` RULE. `exists:courses,uuid` is a raw
            | query that answers a different question — it would pass for another
            | teacher's course, and the announcement would then address their
            | students. The row is resolved inside `CreateAnnouncement`, scoped to
            | the publisher's own workspace. `WorkspaceRules::exists()` would also
            | serve, but the Action has to fetch the id anyway.
            */
            /*
            | ⚠️ AND NO `required_unless:scope,all`. It works and it renders
            | «حقل الكورس أو الحصّة مطلوب ما لم يكن النطاق ضمن all» — an Arabic
            | sentence ending in an English enum value, in front of the teacher.
            | `CreateAnnouncement` already refuses a missing target with a
            | sentence written for a person, and the rule belongs in the Action
            | anyway: the seeders and the panel reach it with no request behind
            | them.
            */
            'scope_uuid' => ['nullable', 'uuid'],
            'is_urgent' => ['boolean'],
        ];
    }
}
