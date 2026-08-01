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

    public function subjects(ListPublicTaxonomy $action): JsonResponse
    {
        return response()->json($action->handle(ListPublicTaxonomy::SUBJECTS));
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

    public function teacher(string $uuid, ShowPublicTeacher $action): JsonResponse
    {
        // Not cached: reads are cheaper here than on the lists, and a stale profile
        // is the case where showing withdrawn data hurts most.
        $teacher = $action->handle($uuid);

        $payload = PublicTeacherDetailResource::make($teacher)->resolve();
        $payload['courses'] = PublicCourseCardResource::collection($action->coursesOf($teacher))->resolve();

        return response()->json(['data' => $payload]);
    }
}
