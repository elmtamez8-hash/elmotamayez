<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Actions\ManageLessons;
use App\Modules\Courses\Actions\ReorderTreeNodes;
use App\Modules\Courses\DTOs\LessonData;
use App\Modules\Courses\Http\Requests\ReorderRequest;
use App\Modules\Courses\Http\Requests\StoreLessonRequest;
use App\Modules\Courses\Http\Requests\UpdateLessonRequest;
use App\Modules\Courses\Http\Resources\LessonResource;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use Illuminate\Http\JsonResponse;

class LessonController extends Controller
{
    public function store(StoreLessonRequest $request, Course $course, ManageLessons $action): JsonResponse
    {
        $chapter = $this->chapterFrom($course, $request->string('chapter_uuid')->toString());

        $lesson = $action->create($course, $chapter, LessonData::fromArray($request->validated()));

        return response()->json(LessonResource::make($lesson), 201);
    }

    public function update(
        UpdateLessonRequest $request,
        Course $course,
        Lesson $lesson,
        ManageLessons $action,
    ): JsonResponse {
        $this->assertBelongsToCourse($lesson, $course);

        $chapter = $request->filled('chapter_uuid')
            ? $this->chapterFrom($course, $request->string('chapter_uuid')->toString())
            : null;

        $payload = $request->validated();
        // The type never changes through this path — ChangeLessonType reports
        // what would be lost first. Carrying the current one keeps the DTO one
        // shape for both verbs.
        $payload['type'] = $lesson->type;
        $payload['title'] ??= $lesson->title;

        return response()->json(
            LessonResource::make($action->update($lesson, LessonData::fromArray($payload), $chapter)),
        );
    }

    public function destroy(Course $course, Lesson $lesson, ManageLessons $action): JsonResponse
    {
        $this->authorize('manageLessons', $course);
        $this->assertBelongsToCourse($lesson, $course);

        $action->delete($lesson);

        return response()->json(null, 204);
    }

    public function reorder(
        ReorderRequest $request,
        Course $course,
        Chapter $chapter,
        ReorderTreeNodes $action,
    ): JsonResponse {
        $this->authorize('manageLessons', $course);
        abort_unless($chapter->course_id === $course->getKey(), 404);
        $request->assertVersionMatches($course);

        $action->handle($course, $chapter->lessons()->getQuery(), $request->orderedUuids());

        return response()->json(['structure_version' => $course->refresh()->structure_version]);
    }

    private function chapterFrom(Course $course, string $uuid): Chapter
    {
        /** @var Chapter $chapter */
        $chapter = $course->chapters()->where('uuid', $uuid)->firstOrFail();

        return $chapter;
    }

    private function assertBelongsToCourse(Lesson $lesson, Course $course): void
    {
        abort_unless($lesson->course_id === $course->getKey(), 404);
    }
}
