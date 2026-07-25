<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Http\Requests\StoreSectionRequest;
use App\Modules\Courses\Http\Requests\UpdateSectionRequest;
use App\Modules\Courses\Http\Resources\SectionResource;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Section;
use Illuminate\Http\JsonResponse;

class SectionController extends Controller
{
    public function index(Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        $sections = $course->sections()->with('chapters.lessons')->orderBy('order')->get();

        return response()->json(SectionResource::collection($sections));
    }

    public function store(StoreSectionRequest $request, Course $course): JsonResponse
    {
        $section = $course->sections()->create($request->validated());

        return response()->json(SectionResource::make($section), 201);
    }

    public function update(UpdateSectionRequest $request, Course $course, Section $section): JsonResponse
    {
        $this->authorize('manageLessons', $course);

        if ($section->course_id !== $course->getKey()) {
            return response()->json(['message' => 'Section not found.'], 404);
        }

        $section->update($request->validated());

        return response()->json(SectionResource::make($section->fresh()));
    }

    public function destroy(Course $course, Section $section): JsonResponse
    {
        $this->authorize('manageLessons', $course);

        if ($section->course_id !== $course->getKey()) {
            return response()->json(['message' => 'Section not found.'], 404);
        }

        $section->delete();

        return response()->json(null, 204);
    }
}
