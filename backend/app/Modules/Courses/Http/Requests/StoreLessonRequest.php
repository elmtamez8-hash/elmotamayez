<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Course;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageLessons', $this->route('course')) ?? false;
    }

    /**
     * One parent, not two.
     *
     * This used to require `section_id` AND `chapter_id`, each checked against
     * the course and neither against the other — so a lesson could be created
     * with a section from one branch and a chapter from another, inside the same
     * course, and every validation rule passed. A chapter already knows its
     * section; asking for both invites them to disagree.
     *
     * Both were also serial ids in the payload, which the constitution forbids.
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
            'chapter_uuid' => ['required', 'string', $chapterRule],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(LessonType::class)],
            'content' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string', 'url', 'starts_with:https://', 'max:2048'],
            'reference_uuid' => ['nullable', 'string'],
            'exam_gate' => ['nullable', Rule::enum(ExamGate::class)],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'is_preview' => ['nullable', 'boolean'],
            'is_free' => ['nullable', 'boolean'],
            // FR-041 — the teacher classifies their own content. Nullable, so an
            // untouched checkbox is not an instruction to clear the flag.
            'is_high_value' => ['nullable', 'boolean'],
        ];
    }
}
