<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Support\RelationType;
use App\Modules\Marketplace\Models\GradeLevel;
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
            'grade_level_slug' => ['nullable', 'string', Rule::in($this->gradeLevelSlugs())],
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
            'grade_level_slug.in' => 'اختر مرحلة دراسية من القائمة.',
            'permissions.min' => 'اختر ما يطّلع عليه هذا المرتبط على الأقل في بند واحد.',
        ];
    }

    /**
     * Platform vocabulary, read across workspaces on purpose — a guardian belongs
     * to none (Constitution I; same reasoning as RegisterStudentRequest).
     *
     * @return list<string>
     */
    private function gradeLevelSlugs(): array
    {
        /** @var list<string> */
        return GradeLevel::query()
            ->withoutWorkspaceScope()
            ->where('is_active', true)
            ->distinct()
            ->pluck('slug')
            ->all();
    }
}
