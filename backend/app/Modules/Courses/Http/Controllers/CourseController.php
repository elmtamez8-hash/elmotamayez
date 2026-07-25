<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Actions\CreateCourse;
use App\Modules\Courses\Actions\PublishCourse;
use App\Modules\Courses\DTOs\CreateCourseDTO;
use App\Modules\Courses\Http\Requests\CreateCourseRequest;
use App\Modules\Courses\Http\Requests\UpdateCourseRequest;
use App\Modules\Courses\Http\Resources\CourseResource;
use App\Modules\Courses\Models\Course;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function show(Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        return response()->json(CourseResource::make($course->load(['sections.chapters.lessons'])));
    }

    public function store(CreateCourseRequest $request, CreateCourse $action): JsonResponse
    {
        $course = $action->handle(CreateCourseDTO::fromArray($request->validated()), $this->currentUser($request));

        return response()->json(CourseResource::make($course), 201);
    }

    public function update(UpdateCourseRequest $request, Course $course): JsonResponse
    {
        $this->authorize('update', $course);

        $course->update($request->validated());

        return response()->json(CourseResource::make($course->fresh()));
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
