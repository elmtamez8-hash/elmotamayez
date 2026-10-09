<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Actions\SetCourseTrialLesson;
use App\Modules\Courses\DTOs\SetTrialLessonData;
use App\Modules\Courses\Http\Requests\SetCourseTrialLessonRequest;
use App\Modules\Courses\Http\Resources\CourseTrialLessonResource;
use App\Modules\Courses\Models\Course;
use Illuminate\Http\JsonResponse;

/** `PUT /courses/{course}/trial-lesson` — spec 040. */
final class CourseTrialLessonController extends Controller
{
    public function update(SetCourseTrialLessonRequest $request, Course $course, SetCourseTrialLesson $action): JsonResponse
    {
        $this->authorize('chooseTrialLesson', $course);

        /** @var array{lesson?: string|null, replacing?: string|null} $validated */
        $validated = $request->validated();

        $course = $action->handle($course, SetTrialLessonData::fromArray($validated));

        return response()->json(['data' => CourseTrialLessonResource::make($course)->resolve()]);
    }
}
