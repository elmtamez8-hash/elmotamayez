<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Courses\Support\MarkdownRenderer;
use App\Modules\Courses\Support\ReferenceSummary;
use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Http\Resources\EnrollmentResource;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $enrollments = Enrollment::query()
            ->where('student_user_id', $this->currentUser($request)->getKey())
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

        $enrollment = $action->handle($course, $this->currentUser($request));

        return response()->json(EnrollmentResource::make($enrollment), 201);
    }

    public function showLesson(Request $request, Enrollment $enrollment, Lesson $lesson): JsonResponse
    {
        $this->authorize('view', $enrollment);

        // `completeLesson` below has always checked this and this method never
        // did — so any lesson uuid could be read through an enrolment of one's
        // own, and `canAccessLesson` answers about sequence, not about which
        // course the lesson is in.
        if ($lesson->course_id !== $enrollment->course_id) {
            return response()->json(['message' => 'This lesson does not belong to the enrolled course.'], 404);
        }

        return response()->json($this->lessonPayload($enrollment, $lesson));
    }

    /**
     * The same lesson, without making the client carry an enrolment uuid.
     *
     * `/learn/{lesson}` is reached from a course page that knows the COURSE
     * uuid, not the enrolment's, so every caller was either doing a second
     * lookup to turn one into the other or — as the student player did — not
     * calling this at all and rendering video and nothing else.
     *
     * The server has the viewer and the lesson, which is everything needed to
     * find the enrolment. A 404 when there is none: that a particular lesson
     * exists is itself information.
     */
    public function showLessonForViewer(Request $request, Lesson $lesson): JsonResponse
    {
        $enrollment = Enrollment::query()
            ->where('course_id', $lesson->course_id)
            ->where('student_user_id', $this->currentUser($request)->getKey())
            ->first();

        if ($enrollment === null) {
            return response()->json(['message' => 'لا تملك تسجيلاً في هذا الكورس.'], 404);
        }

        $this->authorize('view', $enrollment);

        return response()->json($this->lessonPayload($enrollment, $lesson));
    }

    /**
     * What a student may see of one item.
     *
     * Shared by both entrances so the two cannot drift — the moment they do, one
     * of them is showing a field the other decided to withhold.
     *
     * @return array<string, mixed>
     */
    private function lessonPayload(Enrollment $enrollment, Lesson $lesson): array
    {
        $lesson->load(['section', 'chapter', 'attachments']);
        $access = $enrollment->accessTo($lesson);
        $canAccess = $access->allowed;
        $type = LessonType::from($lesson->type);

        return [
            'lesson' => [
                'uuid' => $lesson->uuid,
                'title' => $lesson->title,
                'type' => $type->value,
                'type_label' => $type->label(),
                'is_completable' => LessonTypeRegistry::isCompletable($type),
                'content' => $canAccess ? $lesson->content : null,
                // Derived per response, never stored. The student page was
                // rendering the Markdown SOURCE, asterisks and all.
                'content_html' => $canAccess ? MarkdownRenderer::toHtml($lesson->content) : '',
                'external_url' => $canAccess ? $lesson->external_url : null,
                'has_asset' => $canAccess && $lesson->mediaAsset !== null,
                // The exam this item places, or the session it holds a spot for
                // — with the date and the state a `live_session` row needs to
                // say something other than "coming soon" forever (FR-047,
                // FR-048). Not gated on access: knowing that the item ahead is
                // an exam asking for 60% is what tells the student what to do.
                'reference' => ReferenceSummary::for($lesson),
                'exam_gate' => $lesson->exam_gate?->value,
                'exam_gate_label' => $lesson->exam_gate?->label(),
                // FR-019: attachments appear beside the item for the student.
                // Each is opened through its own short-lived grant — there is no
                // permanent path to one, so the list carries a uuid to ask with,
                // never a URL.
                'attachments' => $canAccess ? $lesson->attachments->map(
                    fn (MediaAsset $attachment): array => [
                        'uuid' => $attachment->uuid,
                        'original_filename' => $attachment->original_filename,
                        'kind' => $attachment->kind->value,
                        'kind_label' => $attachment->kind->label(),
                        'is_downloadable' => $attachment->is_downloadable,
                        'is_ready' => $attachment->isPlayable(),
                    ],
                )->values()->all() : [],
            ],
            'can_access' => $canAccess,
            // Why not, in words the student can act on (FR-043). A lock with
            // nothing after it is a support ticket — and an exam gate is
            // invisible from the locked item, since what has to happen is on a
            // different page.
            'blocked_reason' => $access->code,
            'blocked_message' => $access->message,
            'blocked_by_title' => $access->blockedByTitle,
            'enrollment_uuid' => $enrollment->uuid,
        ];
    }

    public function completeLesson(Request $request, Enrollment $enrollment, Lesson $lesson, MarkLessonComplete $action): JsonResponse
    {
        $this->authorize('completeLessons', $enrollment);

        if ($lesson->course_id !== $enrollment->course_id) {
            return response()->json(['message' => 'This lesson does not belong to the enrolled course.'], 404);
        }

        $lesson->load(['section', 'chapter']);
        $access = $enrollment->accessTo($lesson);

        if (! $access->allowed) {
            // The same sentence the item's own screen shows, rather than the raw
            // English string that used to be here — a refusal that names the
            // previous lesson is one the student can act on, and there is no
            // reason for the two paths to word it differently.
            return response()->json([
                'message' => $access->message,
                'code' => $access->code,
            ], 422);
        }

        $progress = $action->handle($enrollment, $lesson->getKey());
        $enrollment->refresh();

        return response()->json([
            'status' => $progress->status,
            'course_completed' => $enrollment->isCompleted(),
            'progress_pct' => $enrollment->progress_pct,
        ]);
    }
}
