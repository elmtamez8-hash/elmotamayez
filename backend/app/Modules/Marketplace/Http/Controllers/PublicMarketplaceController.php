<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Actions\Public\GetMarketplaceHome;
use App\Modules\Marketplace\Actions\Public\GetMarketplaceStats;
use App\Modules\Marketplace\Actions\Public\ListPublicCourses;
use App\Modules\Marketplace\Actions\Public\ListPublicTaxonomy;
use App\Modules\Marketplace\Actions\Public\ListPublicTeachers;
use App\Modules\Marketplace\Actions\Public\ShowPublicTeacher;
use App\Modules\Marketplace\Http\Requests\ListPublicCoursesRequest;
use App\Modules\Marketplace\Http\Requests\ListPublicTeachersRequest;
use App\Modules\Marketplace\Http\Resources\PublicCourseCardResource;
use App\Modules\Marketplace\Http\Resources\PublicTeacherCardResource;
use App\Modules\Marketplace\Http\Resources\PublicTeacherDetailResource;
use App\Modules\Marketplace\Support\MarketplaceCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Unauthenticated marketplace reads.
 *
 * Every action reached from here runs on a request with no user, which means
 * WorkspaceScope is inert. Do not add an endpoint to this controller whose Action
 * does not start from publiclyListed().
 */
class PublicMarketplaceController extends Controller
{
    public function home(GetMarketplaceHome $action): JsonResponse
    {
        return response()->json($action->handle());
    }

    public function stats(GetMarketplaceStats $action): JsonResponse
    {
        return response()->json($action->handle());
    }

    /**
     * Subjects, optionally narrowed to one stage.
     *
     * `?grade_level=secondary` answers "what is taught to secondary students" —
     * derived from the teachers, not from a table of assumptions. The list page
     * asks for it so the subject control does not open with every subject on the
     * platform, which on a phone is a wall of options before the visitor has
     * said anything about who they are shopping for.
     */
    public function subjects(Request $request, ListPublicTaxonomy $action): JsonResponse
    {
        // Validated, not trusted: the value reaches a `where` on a slug column.
        // Eloquent binds it either way, but a 200-character query string has no
        // business becoming a cache key.
        $validated = $request->validate([
            'grade_level' => ['nullable', 'string', 'max:60'],
        ]);

        $gradeLevel = $validated['grade_level'] ?? null;

        return response()->json($action->handle(
            ListPublicTaxonomy::SUBJECTS,
            is_string($gradeLevel) && $gradeLevel !== '' ? $gradeLevel : null,
        ));
    }

    public function gradeLevels(ListPublicTaxonomy $action): JsonResponse
    {
        return response()->json($action->handle(ListPublicTaxonomy::GRADE_LEVELS));
    }

    public function teachers(ListPublicTeachersRequest $request, ListPublicTeachers $action): JsonResponse
    {
        $filters = $request->toDto();

        $payload = Cache::remember(
            $filters->cacheKey(),
            MarketplaceCache::ttl(),
            function () use ($action, $filters): array {
                $teachers = $action->handle($filters);

                return [
                    'data' => PublicTeacherCardResource::collection($teachers->items())->resolve(),
                    'meta' => [
                        'current_page' => $teachers->currentPage(),
                        'per_page' => $teachers->perPage(),
                        'total' => $teachers->total(),
                        'last_page' => $teachers->lastPage(),
                        'filters' => $filters->toFilterMap(),
                    ],
                ];
            },
        );

        return response()->json($payload);
    }

    public function courses(ListPublicCoursesRequest $request, ListPublicCourses $action): JsonResponse
    {
        $filters = $request->toDto();

        $payload = Cache::remember(
            $filters->cacheKey(),
            MarketplaceCache::ttl(),
            function () use ($action, $filters): array {
                $courses = $action->handle($filters);

                return [
                    'data' => PublicCourseCardResource::collection($courses->items())->resolve(),
                    'meta' => [
                        'current_page' => $courses->currentPage(),
                        'per_page' => $courses->perPage(),
                        'total' => $courses->total(),
                        'last_page' => $courses->lastPage(),
                        'filters' => $filters->toFilterMap(),
                    ],
                ];
            },
        );

        return response()->json($payload);
    }

    public function teacher(string $key, ShowPublicTeacher $action): JsonResponse
    {
        // Not cached: reads are cheaper here than on the lists, and a stale profile
        // is the case where showing withdrawn data hurts most.
        $teacher = $action->handle($key);

        $payload = PublicTeacherDetailResource::make($teacher)->resolve();
        $payload['courses'] = PublicCourseCardResource::collection($action->coursesOf($teacher))->resolve();
        $payload['reviews'] = $action->reviewsOf($teacher);

        return response()->json(['data' => $payload]);
    }
}
