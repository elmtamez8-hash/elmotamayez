<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Actions\ManageSections;
use App\Modules\Courses\Actions\PreviewPublishImpact;
use App\Modules\Courses\Actions\PublishTreeNodes;
use App\Modules\Courses\Actions\ReorderTreeNodes;
use App\Modules\Courses\Http\Requests\PublishPreviewRequest;
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
     * The eager loads and their column list live on the Resource, because the
     * 409 body needs the identical tree and a second copy of that list would
     * drift — see CourseTreeResource::for().
     */
    public function tree(Course $course): JsonResponse
    {
        $this->authorize('manageLessons', $course);

        return response()->json(CourseTreeResource::for($course));
    }

    /**
     * What this publish would do to the students already enrolled (`FR-049`).
     *
     * A read, so it sits outside `throttle:authoring` with the other reads. It
     * answers with the item list it costed, and the client publishes THAT list —
     * a preview computed over one batch and a publish sent with another is two
     * different questions with one answer shown, which is precisely the drift
     * `SC-018` forbids.
     */
    public function publishPreview(
        PublishPreviewRequest $request,
        Course $course,
        PreviewPublishImpact $action,
    ): JsonResponse {
        return response()->json($action->handle($course, $request->items()));
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
