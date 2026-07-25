<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Courses\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageLessons', $this->route('course')) ?? false;
    }

    public function rules(): array
    {
        /** @var Course|null $course */
        $course = $this->route('course');

        $sectionRule = Rule::exists('course_sections', 'id');
        $chapterRule = Rule::exists('course_chapters', 'id');
        if ($course !== null) {
            $sectionRule->where('course_id', $course->id);
            $chapterRule->where('course_id', $course->id);
        }

        return [
            'section_id' => ['sometimes', 'integer', $sectionRule],
            'chapter_id' => ['sometimes', 'integer', $chapterRule],
            'title' => ['sometimes', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'in:video,pdf,article,file'],
            'content' => ['nullable', 'string'],
            'order' => ['nullable', 'integer', 'min:0'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'is_preview' => ['nullable', 'boolean'],
            'is_free' => ['nullable', 'boolean'],
            'media' => ['nullable', 'array'],
        ];
    }
}
