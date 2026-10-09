<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /courses/{course}/trial-lesson` (spec 040). Who may is the policy's
 * question (`chooseTrialLesson`), asked in the controller; which lesson may is
 * `TrialLessonRule`'s, asked in the Action, so the seeders and the panel meet it.
 */
final class SetCourseTrialLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Tenant-owned table: never a bare `exists:` (it skips the scope).
            'lesson' => ['present', 'nullable', 'uuid', WorkspaceRules::exists('lessons', 'uuid')],
            'replacing' => ['nullable', 'uuid', 'required_without:lesson'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lesson.exists' => 'اختر درساً من هذا الكورس.',
            'replacing.required_without' => 'أعد تحميل الصفحة ثم ألغِ الحصة التجريبية.',
        ];
    }
}
