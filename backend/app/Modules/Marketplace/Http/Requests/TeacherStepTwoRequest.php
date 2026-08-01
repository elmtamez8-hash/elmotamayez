<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TeacherStepTwoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Slugs, not ids — the taxonomy row differs per workspace and the
            // applicant has not been placed in one yet.
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*' => ['string', 'max:100'],
            'grade_levels' => ['required', 'array', 'min:1'],
            'grade_levels.*' => ['string', 'max:100'],
            'years_experience' => ['required', 'integer', 'min:0', 'max:60'],
            'qualifications' => ['array'],
            'qualifications.*' => ['string', 'max:255'],
            'teaching_languages' => ['required', 'array', 'min:1'],
            'teaching_languages.*' => ['string', 'max:5'],
            'headline' => ['required', 'string', 'max:150'],
            'bio' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'هذا الحقل مطلوب.',
            'subjects.min' => 'اختر مادة واحدة على الأقل.',
            'grade_levels.min' => 'اختر مرحلة دراسية واحدة على الأقل.',
            'teaching_languages.min' => 'اختر لغة تدريس واحدة على الأقل.',
        ];
    }
}
