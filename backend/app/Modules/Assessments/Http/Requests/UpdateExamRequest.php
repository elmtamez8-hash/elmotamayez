<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::EXAMS_UPDATE) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'course_id' => ['nullable', 'integer', WorkspaceRules::exists('courses')],
            /*
            | ⛔ THE COURSE ARRIVES AS A UUID (2026-09-30) — `course_id` is an
            | autoincrement id no screen in the product has, so «اختبار جديد»
            | posted no course at all and a CONFINED assistant (who may not set a
            | course-less paper) could not create an exam. Resolved to the id in
            | `ExamController`; `course_id` stays for the panel and old clients,
            | and `course` wins when both are sent.
            */
            'course' => ['nullable', 'uuid', WorkspaceRules::exists('courses', 'uuid')],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'passing_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_attempts' => ['nullable', 'integer', 'min:1'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'shuffle_answers' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:draft,published,archived'],
        ];
    }
}
