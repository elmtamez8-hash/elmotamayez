<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Resources;

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\TrialLessonRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course's «حصة تجريبية» as the teacher sees it after a change (spec 040).
 *
 * @mixin Course
 */
final class CourseTrialLessonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Course $course */
        $course = $this->resource;
        $lesson = $course->trial_lesson_id === null
            ? null
            : Lesson::query()->withoutWorkspaceScope()->find($course->trial_lesson_id);

        return [
            'trial_lesson' => $lesson === null ? null : [
                'uuid' => (string) $lesson->uuid,
                'title' => (string) $lesson->title,
                'kind' => (string) $lesson->type,
            ],
            'trial_status' => TrialLessonRule::statusFor($course),
        ];
    }
}
