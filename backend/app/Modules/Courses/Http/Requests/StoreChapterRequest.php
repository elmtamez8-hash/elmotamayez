<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Courses\Models\Course;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;

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

        // WorkspaceRules, not Rule::exists. Laravel's exists rule is a raw query
        // that never sees the global scope, so the plain form lets a payload
        // name another tenant's section by id and have it validate.
        $sectionRule = WorkspaceRules::exists('course_sections', 'uuid');

        if ($course !== null) {
            $sectionRule->where('course_id', $course->getKey());
        }

        return [
            'section_uuid' => ['required', 'string', $sectionRule],
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
