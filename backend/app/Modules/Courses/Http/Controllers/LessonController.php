<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Http\Requests\StoreLessonRequest;
use App\Modules\Courses\Http\Requests\UpdateLessonRequest;
use App\Modules\Courses\Http\Resources\LessonResource;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use Illuminate\Http\JsonResponse;

class LessonController extends Controller
{
    public function store(StoreLessonRequest $request, Course $course): JsonResponse
    {
        $lesson = $course->lessons()->create(array_merge(
            $request->validated(),
            ['workspace_id' => $course->workspace_id],
        ));

        return response()->json(LessonResource::make($lesson), 201);
    }

    public function update(UpdateLessonRequest $request, Course $course, Lesson $lesson): JsonResponse
    {
        $this->authorize('manageLessons', $course);

        if ($lesson->course_id !== $course->getKey()) {
            return response()->json(['message' => 'Lesson not found.'], 404);
        }

        $lesson->update($request->validated());

        return response()->json(LessonResource::make($lesson->fresh()));
    }

    public function destroy(Course $course, Lesson $lesson): JsonResponse
    {
        $this->authorize('manageLessons', $course);

        if ($lesson->course_id !== $course->getKey()) {
            return response()->json(['message' => 'Lesson not found.'], 404);
        }

        $lesson->delete();

        return response()->json(null, 204);
    }
}
