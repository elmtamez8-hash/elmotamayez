<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Marketplace\Models\GradeLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddChildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'age' => ['nullable', 'integer', 'between:3,25'],
            'grade_level_slug' => ['nullable', 'string', Rule::in($this->gradeLevelSlugs())],
            // No exists rule: AddChild resolves the uuid and answers "not found"
            // and "not a student" identically, so validation must not leak the
            // difference by rejecting one earlier than the other.
            'child_uuid' => ['nullable', 'string', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم الطالب مطلوب.',
            'age.between' => 'أدخل عمراً بين 3 و25 سنة.',
            'grade_level_slug.in' => 'اختر مرحلة دراسية من القائمة.',
        ];
    }

    /**
     * Platform vocabulary, read across workspaces on purpose — a parent belongs to
     * none (Constitution I; same reasoning as RegisterStudentRequest).
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
