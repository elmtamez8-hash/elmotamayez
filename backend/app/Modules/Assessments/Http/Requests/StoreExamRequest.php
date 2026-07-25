<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::EXAMS_CREATE) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('status')) {
            $this->merge(['status' => 'draft']);
        }
    }

    public function rules(): array
    {
        return [
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'title' => ['required', 'string', 'max:255'],
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
