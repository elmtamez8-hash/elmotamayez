<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Actions\ManageSections;
use App\Modules\Courses\Actions\ReorderTreeNodes;
use App\Modules\Courses\Http\Requests\ReorderRequest;
use App\Modules\Courses\Http\Requests\StoreSectionRequest;
use App\Modules\Courses\Http\Requests\UpdateSectionRequest;
use App\Modules\Courses\Http\Resources\CourseSectionResource;
use App\Modules\Courses\Http\Resources\SectionResource;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Section;
use Illuminate\Http\JsonResponse;

class SectionController extends Controller
{
    /**
     * The STUDENT-facing tree: published nodes only.
     *
     * Kept separate from the authoring tree rather than unified behind an
     * `?include_drafts=1` parameter. A shared endpoint makes leaking a draft a
     * matter of forgetting a query string, and what leaks is the unfinished
     * lesson itself.
     */
    public function index(Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        $sections = $course->sections()
            ->published()
            ->with([
                'chapters' => fn ($query) => $query->published(),
                'chapters.lessons' => fn ($query) => $query->visibleToStudents(),
            ])
            ->orderBy('order')
            ->get();

        return response()->json(CourseSectionResource::collection($sections));
    }

    public function store(StoreSectionRequest $request, Course $course, ManageSections $action): JsonResponse
    {
        $section = $action->create($course, $request->string('title')->toString());

        return response()->json(SectionResource::make($section), 201);
    }

    public function update(
        UpdateSectionRequest $request,
        Course $course,
        Section $section,
        ManageSections $action,
    ): JsonResponse {
        $this->assertBelongsToCourse($section, $course);

        return response()->json(
            SectionResource::make($action->rename($section, $request->string('title')->toString())),
        );
    }

    public function destroy(Course $course, Section $section, ManageSections $action): JsonResponse
    {
        $this->authorize('manageLessons', $course);
        $this->assertBelongsToCourse($section, $course);

        $action->delete($section);

        return response()->json(null, 204);
    }

    public function reorder(ReorderRequest $request, Course $course, ReorderTreeNodes $action): JsonResponse
    {
        $this->authorize('manageLessons', $course);
        $request->assertVersionMatches($course);

        $action->handle($course, $course->sections()->getQuery(), $request->orderedUuids());

        return response()->json(['structure_version' => $course->refresh()->structure_version]);
    }

    private function assertBelongsToCourse(Section $section, Course $course): void
    {
        abort_unless($section->course_id === $course->getKey(), 404);
    }
}
