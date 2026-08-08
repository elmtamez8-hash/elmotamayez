<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Actions\ManageChapters;
use App\Modules\Courses\Actions\ReorderTreeNodes;
use App\Modules\Courses\Http\Requests\ReorderRequest;
use App\Modules\Courses\Http\Requests\StoreChapterRequest;
use App\Modules\Courses\Http\Requests\UpdateChapterRequest;
use App\Modules\Courses\Http\Resources\ChapterResource;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Section;
use Illuminate\Http\JsonResponse;

class ChapterController extends Controller
{
    public function store(StoreChapterRequest $request, Course $course, ManageChapters $action): JsonResponse
    {
        $section = $course->sections()
            ->where('uuid', $request->string('section_uuid')->toString())
            ->firstOrFail();

        $chapter = $action->create($course, $section, $request->string('title')->toString());

        return response()->json(ChapterResource::make($chapter), 201);
    }

    public function update(
        UpdateChapterRequest $request,
        Course $course,
        Chapter $chapter,
        ManageChapters $action,
    ): JsonResponse {
        $this->assertBelongsToCourse($chapter, $course);

        return response()->json(
            ChapterResource::make($action->rename($chapter, $request->string('title')->toString())),
        );
    }

    public function destroy(Course $course, Chapter $chapter, ManageChapters $action): JsonResponse
    {
        $this->authorize('manageLessons', $course);
        $this->assertBelongsToCourse($chapter, $course);

        $action->delete($chapter);

        return response()->json(null, 204);
    }

    public function reorder(
        ReorderRequest $request,
        Course $course,
        Section $section,
        ReorderTreeNodes $action,
    ): JsonResponse {
        $this->authorize('manageLessons', $course);
        abort_unless($section->course_id === $course->getKey(), 404);
        $request->assertVersionMatches($course);

        $action->handle($course, $section->chapters()->getQuery(), $request->orderedUuids());

        return response()->json(['structure_version' => $course->refresh()->structure_version]);
    }

    private function assertBelongsToCourse(Chapter $chapter, Course $course): void
    {
        abort_unless($chapter->course_id === $course->getKey(), 404);
    }
}
