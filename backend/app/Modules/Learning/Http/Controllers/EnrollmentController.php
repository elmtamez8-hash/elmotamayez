<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Http\Resources\EnrollmentResource;
use App\Modules\Learning\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $enrollments = Enrollment::query()
            ->where('student_user_id', $request->user()->getKey())
            ->with('course')
            ->orderByDesc('enrolled_at')
            ->paginate(15);

        return response()->json(EnrollmentResource::collection($enrollments));
    }

    public function enroll(Request $request, Course $course, EnrollStudent $action): JsonResponse
    {
        $this->authorize('view', $course);

        if (! $course->isPublished()) {
            return response()->json(['message' => 'Course is not available for enrollment.'], 422);
        }

        $enrollment = $action->handle($course, $request->user());

        return response()->json(EnrollmentResource::make($enrollment), 201);
    }

    public function showLesson(Request $request, Enrollment $enrollment, Lesson $lesson): JsonResponse
    {
        $this->authorize('view', $enrollment);

        $lesson->load(['section', 'chapter']);
        $canAccess = $enrollment->canAccessLesson($lesson);

        return response()->json([
            'lesson' => [
                'uuid' => $lesson->uuid,
                'title' => $lesson->title,
                'type' => $lesson->type,
                'content' => $canAccess ? $lesson->content : null,
            ],
            'can_access' => $canAccess,
        ]);
    }

    public function completeLesson(Request $request, Enrollment $enrollment, Lesson $lesson, MarkLessonComplete $action): JsonResponse
    {
        $this->authorize('completeLessons', $enrollment);

        $lesson->load(['section', 'chapter']);
        if (! $enrollment->canAccessLesson($lesson)) {
            return response()->json(['message' => 'You must complete the previous lesson first.'], 422);
        }

        $progress = $action->handle($enrollment, $lesson->getKey());

        return response()->json([
            'status' => $progress->status,
            'course_completed' => $enrollment->fresh()->isCompleted(),
            'progress_pct' => $enrollment->fresh()->progress_pct,
        ]);
    }
}
