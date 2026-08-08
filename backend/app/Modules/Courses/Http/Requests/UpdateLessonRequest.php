<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Courses\Models\Course;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageLessons', $this->route('course')) ?? false;
    }

    /**
     * `type` is absent on purpose: changing it can discard content, so it goes
     * through ChangeLessonType, which reports what will be lost first.
     *
     * `class_session_id` is absent for a harder reason. It decides that a
     * recording is watched by whoever held a SEAT in that session rather than by
     * whoever enrolled in the course, and it is written by the 005 listener and
     * nothing else. An authoring surface that could set it could hand any
     * lesson's entitlement to an arbitrary session's attendees.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Course|null $course */
        $course = $this->route('course');

        $chapterRule = WorkspaceRules::exists('course_chapters', 'uuid');

        if ($course !== null) {
            $chapterRule->where('course_id', $course->getKey());
        }

        return [
            'chapter_uuid' => ['sometimes', 'string', $chapterRule],
            'title' => ['sometimes', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string', 'url', 'starts_with:https://', 'max:2048'],
            'reference_uuid' => ['nullable', 'string'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'is_preview' => ['nullable', 'boolean'],
            'is_free' => ['nullable', 'boolean'],
        ];
    }
}
