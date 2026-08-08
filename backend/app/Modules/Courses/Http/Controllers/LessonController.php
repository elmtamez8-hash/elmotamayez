<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Actions\ChangeLessonType;
use App\Modules\Courses\Actions\ManageLessons;
use App\Modules\Courses\Actions\ReorderTreeNodes;
use App\Modules\Courses\DTOs\LessonData;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Http\Requests\ChangeLessonTypeRequest;
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

    /**
     * One item, in full.
     *
     * The authoring tree deliberately does not carry `content`: it is loaded for
     * every node at once, and a course of forty articles would ship forty
     * article bodies to draw an outline. The editor asks for the one it opens.
     */
    public function show(Course $course, Lesson $lesson): JsonResponse
    {
        $this->authorize('manageLessons', $course);
        $this->assertBelongsToCourse($lesson, $course);

        return response()->json(LessonResource::make($lesson));
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

        // A field the request did not mention keeps its stored value.
        //
        // The DTO has one shape for all ten types, so every field it does not
        // receive arrives as null — and the Action writes what it is given.
        // Renaming an item therefore erased its body. `array_key_exists`, not
        // `??=`: an explicit null is the teacher clearing the field, which is a
        // different instruction from not mentioning it.
        foreach (['content', 'external_url', 'reference_uuid', 'duration_seconds'] as $field) {
            if (! array_key_exists($field, $payload)) {
                $payload[$field] = $field === 'reference_uuid' ? null : $lesson->{$field};
            }
        }

        return response()->json(
            LessonResource::make($action->update($lesson, LessonData::fromArray($payload), $chapter)),
        );
    }

    /**
     * What changing this item's type would discard — asked BEFORE it is done.
     *
     * A separate read rather than a "dry run" flag on the write. A flag that
     * defaults wrong, or that a client forgets, silently performs the very thing
     * this endpoint exists to warn about.
     */
    public function typeChangePreview(
        Course $course,
        Lesson $lesson,
        string $type,
        ChangeLessonType $action,
    ): JsonResponse {
        $this->authorize('manageLessons', $course);
        $this->assertBelongsToCourse($lesson, $course);

        $target = LessonType::tryFrom($type);

        abort_if($target === null, 404);

        return response()->json([
            'type' => $target->value,
            'type_label' => $target->label(),
            'losses' => $action->losses($lesson, $target),
        ]);
    }

    public function changeType(
        ChangeLessonTypeRequest $request,
        Course $course,
        Lesson $lesson,
        ChangeLessonType $action,
    ): JsonResponse {
        $this->assertBelongsToCourse($lesson, $course);

        $target = LessonType::from($request->string('type')->toString());

        return response()->json(LessonResource::make($action->handle($lesson, $target)));
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
