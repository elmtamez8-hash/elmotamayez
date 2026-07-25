<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Courses\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChapterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageLessons', $this->route('course')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Course|null $course */
        $course = $this->route('course');

        $sectionRule = Rule::exists('course_sections', 'id');
        if ($course !== null) {
            $sectionRule->where('course_id', $course->id);
        }

        return [
            'section_id' => ['required', 'integer', $sectionRule],
            'title' => ['required', 'string', 'max:255'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
