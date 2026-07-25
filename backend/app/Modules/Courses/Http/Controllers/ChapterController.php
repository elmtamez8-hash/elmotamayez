<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Http\Requests\StoreChapterRequest;
use App\Modules\Courses\Http\Requests\UpdateChapterRequest;
use App\Modules\Courses\Http\Resources\ChapterResource;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use Illuminate\Http\JsonResponse;

class ChapterController extends Controller
{
    public function store(StoreChapterRequest $request, Course $course): JsonResponse
    {
        $chapter = $course->chapters()->create(array_merge(
            $request->validated(),
            ['workspace_id' => $course->workspace_id],
        ));

        return response()->json(ChapterResource::make($chapter), 201);
    }

    public function update(UpdateChapterRequest $request, Course $course, Chapter $chapter): JsonResponse
    {
        $this->authorize('manageLessons', $course);

        if ($chapter->course_id !== $course->getKey()) {
            return response()->json(['message' => 'Chapter not found.'], 404);
        }

        $chapter->update($request->validated());

        return response()->json(ChapterResource::make($chapter->fresh()));
    }

    public function destroy(Course $course, Chapter $chapter): JsonResponse
    {
        $this->authorize('manageLessons', $course);

        if ($chapter->course_id !== $course->getKey()) {
            return response()->json(['message' => 'Chapter not found.'], 404);
        }

        $chapter->delete();

        return response()->json(null, 204);
    }
}
