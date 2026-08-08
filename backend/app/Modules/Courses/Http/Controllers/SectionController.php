<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Actions\ManageSections;
use App\Modules\Courses\Actions\PublishTreeNodes;
use App\Modules\Courses\Actions\ReorderTreeNodes;
use App\Modules\Courses\Http\Requests\PublishTreeRequest;
use App\Modules\Courses\Http\Requests\ReorderRequest;
use App\Modules\Courses\Http\Requests\StoreSectionRequest;
use App\Modules\Courses\Http\Requests\UpdateSectionRequest;
use App\Modules\Courses\Http\Resources\CourseSectionResource;
use App\Modules\Courses\Http\Resources\CourseTreeResource;
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

    /**
     * The AUTHOR's tree — drafts included.
     *
     * Three eager loads, not one query per node: a Resource runs once per row,
     * so a query inside one is an N+1 by construction. QueryBudgetTest holds
     * this to a fixed count regardless of how big the tree gets.
     */
    public function tree(Course $course): JsonResponse
    {
        $this->authorize('manageLessons', $course);

        $course->load([
            'sections' => fn ($query) => $query->orderBy('order'),
            'sections.chapters' => fn ($query) => $query->orderBy('order'),
            // A column list, because `content` is a longText and this resource
            // emits no body at all — the editor asks for the one item it opens
            // (see LessonController::show). Without it, drawing an outline shipped
            // every article in the course into PHP memory, on the endpoint that is
            // re-read after every authoring write.
            //
            // `QueryBudgetTest` cannot catch this: it counts queries, not bytes,
            // and its fixture stores five-character bodies.
            'sections.chapters.lessons' => fn ($query) => $query
                ->select([
                    'id', 'uuid', 'course_id', 'section_id', 'chapter_id', 'title', 'type',
                    'status', 'order', 'duration_seconds', 'is_preview', 'is_free',
                    'class_session_id', 'reference_id', 'exam_gate',
                ])
                ->orderBy('order'),
        ]);

        return response()->json(CourseTreeResource::make($course));
    }

    /**
     * Publish, unpublish or archive a batch of nodes.
     *
     * Answers with the whole authoring tree rather than the changed rows: a
     * publish moves `structure_version`, and a client left holding the old one
     * would 409 on its very next write.
     */
    public function publishTree(PublishTreeRequest $request, Course $course, PublishTreeNodes $action): JsonResponse
    {
        $request->assertVersionMatches($course);

        $action->handle($course, $request->items(), $request->integer('structure_version'));

        return $this->tree($course->refresh());
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

        $action->handle($course, $course->sections()->getQuery(), $request->orderedUuids(), $request->integer('structure_version'));

        return response()->json(['structure_version' => $course->refresh()->structure_version]);
    }

    private function assertBelongsToCourse(Section $section, Course $course): void
    {
        abort_unless($section->course_id === $course->getKey(), 404);
    }
}
