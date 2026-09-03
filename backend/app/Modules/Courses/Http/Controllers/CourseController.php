<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Actions\CreateCourse;
use App\Modules\Courses\Actions\PublishCourse;
use App\Modules\Courses\Actions\ReviewCoursePromoVideo;
use App\Modules\Courses\Actions\SetCoursePromoVideo;
use App\Modules\Courses\DTOs\CreateCourseDTO;
use App\Modules\Courses\Http\Requests\CreateCourseRequest;
use App\Modules\Courses\Http\Requests\ReviewPromoVideoRequest;
use App\Modules\Courses\Http\Requests\UpdateCourseRequest;
use App\Modules\Courses\Http\Resources\CourseResource;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Support\SubjectResolver;
use App\Modules\Marketplace\Models\Subject;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Course::class);

        $searchTerm = $request->string('search')->toString();

        if ($searchTerm !== '') {
            // Scout queries the search engine directly, outside the WorkspaceScope
            // global scope — constrain it or the page is filled with other tenants'
            // hits that then get filtered out during hydration.
            $search = Course::search($searchTerm);

            $workspaceId = app(WorkspaceContext::class)->id();
            if ($workspaceId !== null) {
                $search->where('workspace_id', $workspaceId);
            }

            $courses = $search->paginate(15);
        } else {
            $courses = Course::query()->orderByDesc('created_at')->paginate(15);
        }

        return response()->json(CourseResource::collection($courses));
    }

    /**
     * The course, with its PUBLISHED outline.
     *
     * The eager load used to be a bare `sections.chapters.lessons`, which handed
     * every enrolled student every draft in the course — titles and all — from
     * the one endpoint the detail page calls. The authoring tree that shows
     * drafts is `/courses/{course}/tree`, and it is 403 to anyone without
     * `LESSONS_MANAGE`.
     *
     * Filtered here rather than by a flag on the caller: only one endpoint in
     * this module returns drafts, and it is the one whose name says so.
     * `DraftExposureTest` greps the whole response body for a sentinel title.
     */
    public function show(Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        return response()->json(CourseResource::make($course->load([
            'subject',
            'sections' => fn ($query) => $query->published()->orderBy('order'),
            'sections.chapters' => fn ($query) => $query->published()->orderBy('order'),
            'sections.chapters.lessons' => fn ($query) => $query->visibleToStudents()->orderBy('order'),
        ])));
    }

    /**
     * The subjects a course may be filed under.
     *
     * ⚠️ AN AUTHENTICATED ROUTE RATHER THAN THE PUBLIC ONE. `/marketplace/subjects`
     * exists but answers `slug`, `name_ar`, `icon` and a teacher count — a
     * visitor's payload, guarded by `PublicFieldAllowlist`, with no uuid in it.
     * Adding one there to save a route would widen a public payload for the
     * convenience of an authoring form.
     *
     * No permission beyond being signed in: it is platform reference data that
     * every teacher needs before they can create anything, and it names nobody.
     */
    public function subjects(): JsonResponse
    {
        return response()->json([
            'data' => Subject::query()
                ->orderBy('name_ar')
                ->get(['uuid', 'name_ar'])
                ->map(fn (Subject $subject): array => [
                    'uuid' => (string) $subject->uuid,
                    'label' => (string) $subject->name_ar,
                ])
                ->all(),
        ]);
    }

    public function store(CreateCourseRequest $request, CreateCourse $action): JsonResponse
    {
        $course = $action->handle(CreateCourseDTO::fromArray($request->validated()), $this->currentUser($request));

        return response()->json(CourseResource::make($course), 201);
    }

    public function update(UpdateCourseRequest $request, Course $course): JsonResponse
    {
        $this->authorize('update', $course);

        $data = $request->validated();

        /*
        | ⚠️ THE UUID BECOMES AN ID BEFORE THE UPDATE, and `subject` never reaches
        | `update()`. It is not a column — a mass assignment carrying it would be
        | discarded in silence, which is precisely how `subject_id` came to be
        | NULL on every course on the platform: fillable since 007 and written by
        | nobody.
        */
        if (array_key_exists('subject', $data)) {
            $data['subject_id'] = SubjectResolver::id($data['subject']);
        }

        unset($data['subject']);

        /*
        | ⚠️ THE PROMO LINK GOES THROUGH ITS ACTION, NOT THROUGH `update()`
        | (018 · FR-009). `promo_video_url` is not a column, and writing the id
        | by mass assignment would skip the marketplace condition AND leave the
        | previous approval standing over a new video — which is the whole point
        | of doing it in one statement inside the action.
        */
        $promoUrl = $data['promo_video_url'] ?? null;
        $hasPromoKey = array_key_exists('promo_video_url', $data);
        unset($data['promo_video_url']);

        /*
        | ⚠️ ONE TRANSACTION, AND THE REASON IS THE REFUSAL BELOW IT.
        | `SetCoursePromoVideo` throws for a teacher who is not publicly listed
        | (FR-009) — and that check cannot move above the update, because the
        | promo write has to happen after it. Left unwrapped, a refused video
        | answers 422 while the title and price the same request carried are
        | already committed: the client is told nothing happened, and something
        | did.
        */
        DB::transaction(function () use ($course, $data, $hasPromoKey, $promoUrl): void {
            $course->update($data);

            if ($hasPromoKey) {
                app(SetCoursePromoVideo::class)->handle($course, is_string($promoUrl) ? $promoUrl : null);
            }
        });

        return response()->json(CourseResource::make($course->fresh()));
    }

    /**
     * The platform decides whether a promo video may play publicly (018 · FR-006).
     *
     * No `authorize()` here: the permission is PLATFORM-level and the action
     * asks for it directly. A policy on `Course` would be answered inside the
     * teacher's workspace, which is the wrong question entirely — and
     * `Gate::before` waves a super admin past a policy anyway.
     */
    public function reviewPromoVideo(ReviewPromoVideoRequest $request, string $courseUuid, ReviewCoursePromoVideo $action): JsonResponse
    {
        $reviewed = $action->handle(
            $this->currentUser($request),
            $courseUuid,
            (string) $request->validated('decision'),
            $request->validated('reason'),
        );

        return response()->json(CourseResource::make($reviewed));
    }

    public function publish(Course $course, PublishCourse $action): JsonResponse
    {
        $this->authorize('publish', $course);

        return response()->json(CourseResource::make($action->handle($course)));
    }

    public function destroy(Course $course): JsonResponse
    {
        $this->authorize('delete', $course);

        $course->delete();

        return response()->json(null, 204);
    }
}
