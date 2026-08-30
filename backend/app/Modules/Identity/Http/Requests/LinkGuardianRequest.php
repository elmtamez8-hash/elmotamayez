<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Support\RelationType;
use App\Modules\Marketplace\Models\SchoolYear;
use App\Shared\Support\GuardianPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkGuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'student_name' => ['required', 'string', 'max:150'],
            'age' => ['nullable', 'integer', 'between:3,25'],
            /*
            | Spec 022 · FR-005 — the child's individual YEAR. Nullable, unlike
            | the student's own form: a guardian adding a child they know little
            | about must not be blocked on it, and NULL keeps «we never asked»
            | meaningful.
            |
            | `activelyOffered()` is the shared predicate — the same one the
            | public read and the student's own form use, so no door accepts what
            | no screen shows.
            */
            'school_year_slug' => ['nullable', 'string', Rule::in($this->offeredSchoolYearSlugs())],
            // No exists rule: LinkGuardian resolves the uuid and answers "not
            // found" and "not a student" identically, so validation must not leak
            // the difference by rejecting one earlier than the other.
            'student_uuid' => ['nullable', 'string', 'uuid'],
            'relation_type' => ['required', Rule::enum(RelationType::class)],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [Rule::in(GuardianPermission::values())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'student_name.required' => 'اسم الطالب مطلوب.',
            'age.between' => 'أدخل عمراً بين 3 و25 سنة.',
            'school_year_slug.in' => 'اختر الصف الدراسي من القائمة.',
            'permissions.min' => 'اختر ما يطّلع عليه هذا المرتبط على الأقل في بند واحد.',
        ];
    }

    /**
     * Platform vocabulary, read across workspaces on purpose — a guardian belongs
     * to none (Constitution I; same reasoning as RegisterStudentRequest).
     *
     * @return list<string>
     */
    private function offeredSchoolYearSlugs(): array
    {
        /** @var list<string> */
        return SchoolYear::query()
            ->activelyOffered()
            ->pluck('slug')
            ->all();
    }
}
