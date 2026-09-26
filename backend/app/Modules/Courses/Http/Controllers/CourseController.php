<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Actions\ChangeCourseStatus;
use App\Modules\Courses\Actions\CreateCourse;
use App\Modules\Courses\Actions\PublishCourse;
use App\Modules\Courses\Actions\ReviewCoursePromoVideo;
use App\Modules\Courses\Actions\SetCoursePromoVideo;
use App\Modules\Courses\DTOs\CreateCourseDTO;
use App\Modules\Courses\Enums\CourseStatus;
use App\Modules\Courses\Http\Requests\CreateCourseRequest;
use App\Modules\Courses\Http\Requests\ReviewPromoVideoRequest;
use App\Modules\Courses\Http\Requests\UpdateCourseRequest;
use App\Modules\Courses\Http\Resources\CourseResource;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Support\SubjectResolver;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseController extends Controller
{
    /**
     * The teacher's own courses.
     *
     * ⚠️ THE ENVELOPE WAS BEING DROPPED. `response()->json(Resource::collection($paginator))`
     * never calls `toResponse()`, so `links` and `meta` vanished in silence and
     * every reader sat on page one with nothing saying there was a page two —
     * the defect `/enrollments` and `/cms/articles` were each fixed for. The
     * shape changes from a bare array to `{data, links, meta}`; every caller in
     * `frontend/src` already reads `res.data ?? []`, which on a bare array is
     * `undefined`, so they were all reading the fix's shape already.
     */
    public function index(Request $request, CohortDirectory $cohorts): JsonResponse
    {
        $this->authorize('viewAny', Course::class);

        $searchTerm = $request->string('search')->toString();

        /*
        | ⚠️ THE PAGE ASKS FOR ITS OWN SIZE, AND THE CEILING IS THE POINT.
        | The management screen filters by stage and subject in the browser over
        | the whole set — facets built from one page of fifteen offer only what
        | that page happens to contain, which is a filter that lies. Measured
        | 2026-09-09: the largest workspace on the platform holds 82 courses and
        | every other one holds four or fewer.
        |
        | ponytail: 200 is a real ceiling, and the client is told when it bites
        | (`meta.total` against what it received). Past that the honest fix is a
        | server-side filter with facets computed over the whole set — not a
        | bigger number.
        */
        $perPage = min(max($request->integer('per_page', 15), 1), 200);

        /*
        | ⛔ `viewAny` IS `COURSES_VIEW`, AND THE STUDENT ROLE HOLDS IT — so a
        | student member of a workspace read every DRAFT course here. Whoever
        | cannot edit a course sees the published ones only, the same line
        | `CoursePolicy::view()` draws: the workspace question is about drafts.
        */
        $publishedOnly = ! $this->currentUser($request)->can(Permissions::COURSES_UPDATE);

        if ($searchTerm !== '') {
            // Scout queries the search engine directly, outside the WorkspaceScope
            // global scope — constrain it or the page is filled with other tenants'
            // hits that then get filtered out during hydration.
            $search = Course::search($searchTerm);

            $workspaceId = app(WorkspaceContext::class)->id();
            if ($workspaceId !== null) {
                $search->where('workspace_id', $workspaceId);
            }

            /*
            | ⚠️ **`query()` لأنّ الترطيبَ هو ما يُحمِّلُ السمة.** Scout يسألُ
            | المحرّكَ عن المعرّفاتِ ثمّ يجلبُ الصفوفَ باستعلامِ Eloquent، وهذا
            | المُغلَقُ هو المنفذُ الوحيدُ إليه. وبدونِه يخرجُ `has_sessions`
            | **false** لكلِّ صفٍّ في نتائجِ البحثِ وحدَها — نصفٌ صامتٌ من
            | الحقيقة، وهو بعينِه شكلُ العطلِ الذي يُصلِحُه هذا الفرع.
            */
            /*
            | ⚠️ **و`with('subject')` هنا كذلك، وغيابُه لم يكنْ N+1 بل صمتاً.**
            | `CourseResource` يقرأُ المادّةَ بـ`whenLoaded`، فالفرعُ غيرُ
            | المُحمَّلِ لا يُكلِّفُ استعلاماً زائداً — **يُسقِطُ المفتاحَ من كلِّ
            | صفٍّ في نتائجِ البحثِ وحدَها**، وهي عائلةُ «ميزانيّةُ الاستعلاماتِ
            | لا ترى تحميلاً محذوفاً بجوارِ `whenLoaded`» المسجَّلةُ في هذا
            | المستودع. والفرعُ الآخَرُ تحتَه يُحمِّلُها منذُ البداية.
            */
            if ($publishedOnly) {
                // `status` is a filterable attribute (config/scout.php), so the
                // engine drops drafts before they take a result slot.
                $search->where('status', 'published');
            }

            $courses = $search
                ->query(fn ($query) => $query->with('subject')->withExists('classSessions'))
                ->paginate($perPage);
        } else {
            $courses = Course::query()
                ->with('subject')
                // بُولِيّ واحد، لا فصلٌ دراسيٌّ من الحصصِ يُحمَّلُ ليُعَدَّ لا شيء.
                ->withExists('classSessions')
                ->when($publishedOnly, fn ($query) => $query->where('status', 'published'))
                ->orderByDesc('created_at')
                ->paginate($perPage);
        }

        /*
        | ⚠️ ONE READ FOR THE WHOLE PAGE, ASKED THROUGH THE CONTRACT.
        | `Modules/Courses` may not reach into `Modules/Learning` — the cohort is
        | Learning's model — and a per-row read inside `CourseResource` would be
        | one query per course plus one schedule read per course.
        */
        // `items()`, not `getCollection()`: Scout's paginator is typed as the
        // CONTRACT, which declares only the first of the two.
        $items = $courses->items();

        $byCourse = $cohorts->teacherCohortsFor(
            array_values(array_map(fn (Course $course): int => (int) $course->getKey(), $items)),
        );

        $payload = CourseResource::collection($courses)->response()->getData(true);

        $payload['data'] = array_values(array_map(
            fn (Course $course): array => CourseResource::make($course)
                ->withCohorts($byCourse[(int) $course->getKey()] ?? [])
                // `resolve()`, never `toArray()`: the latter skips the filter
                // that strips a `whenLoaded` MissingValue, and `sections` is one
                // — `CourseSectionResource::collection(MissingValue)` then dies
                // inside `json_encode` with «first() on null».
                ->resolve($request),
            $items,
        ));

        return response()->json($payload);
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
    public function show(Request $request, Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        $resource = CourseResource::make($course->loadExists('classSessions')->load([
            'subject',
            'sections' => fn ($query) => $query->published()->orderBy('order'),
            'sections.chapters' => fn ($query) => $query->published()->orderBy('order'),
            'sections.chapters.lessons' => fn ($query) => $query->visibleToStudents()->orderBy('order'),
        ]));

        return response()->json($this->withReach($request, $course, $resource));
    }

    /**
     * The subjects a course may be filed under.
     *
     * ⚠️ AN AUTHENTICATED ROUTE RATHER THAN THE PUBLIC ONE. `/marketplace/subjects`
     * exists but answers `slug`, `name`, `icon` and a teacher count — a
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
                // A translatable column is JSON, so the sort names the locale's
                // key: ordering the raw document sorts by `{"ar":"…` and is right
                // only for as long as every row carries exactly one language.
                ->orderBy('name->'.app()->getLocale())
                ->get(['uuid', 'name'])
                ->map(fn (Subject $subject): array => [
                    'uuid' => (string) $subject->uuid,
                    'label' => (string) $subject->name,
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

    public function publish(Request $request, Course $course, PublishCourse $action): JsonResponse
    {
        $this->authorize('publish', $course);

        $published = $action->handle($course);

        return response()->json($this->withReach($request, $published, CourseResource::make($published)));
    }

    /**
     * Back to draft — the undo the teacher's screen never had (2026-09-26).
     *
     * `ChangeCourseStatus` has carried the `Draft` branch (and its activity-log
     * row) since the panel stopped writing the column raw; only the route was
     * missing, so a teacher who published by mistake could not take it back
     * without an officer. Same permission as publishing: it is the same switch.
     */
    public function unpublish(Request $request, Course $course, ChangeCourseStatus $action): JsonResponse
    {
        $this->authorize('publish', $course);

        $draft = $action->handle($course, CourseStatus::Draft);

        return response()->json($this->withReach($request, $draft, CourseResource::make($draft)));
    }

    /**
     * Stamp «why is this not public» for a reader who may edit the course.
     *
     * ⚠️ THE PROFILE IS READ WITHOUT THE WORKSPACE SCOPE — `teacher_profiles` is
     * tenant-owned, and `ReadPublicCourse` documents what the scoped eager load
     * answers: null, so an approved teacher would read as «not listed».
     */
    private function withReach(Request $request, Course $course, CourseResource $resource): CourseResource
    {
        if ($request->user()?->can('update', $course) !== true) {
            return $resource;
        }

        $course->loadMissing([
            'workspace',
            'creator',
            'creator.teacherProfile' => fn ($query) => $query->withoutWorkspaceScope(),
        ]);

        return $resource->withPublicListingBlockers($course->publicListingBlockers());
    }

    public function destroy(Course $course): JsonResponse
    {
        $this->authorize('delete', $course);

        $course->delete();

        return response()->json(null, 204);
    }
}
