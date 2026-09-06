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
use App\Modules\Learning\Actions\ReadCourseAnnouncements;
use App\Modules\Learning\Actions\ReadCurriculum;
use App\Modules\Learning\Http\Resources\CourseAnnouncementResource;
use App\Modules\Learning\Http\Resources\CurriculumResource;
use App\Modules\Learning\Http\Resources\EnrollmentResource;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Learning\Support\LessonAccess;
use App\Modules\Media\Models\MediaAsset;
use App\Shared\Contracts\SubscriptionDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $enrollments = Enrollment::query()
            ->where('student_user_id', $this->currentUser($request)->getKey())
            // The workspace comes with it: `EnrollmentResource` names the teacher
            // so a student can open the one private conversation with them, and a
            // Resource runs once per row — a query inside it is an N+1 by
            // construction.
            ->with(['course', 'workspace'])
            ->orderByDesc('enrolled_at')
            ->paginate(15);

        return response()->json(EnrollmentResource::collection($enrollments));
    }

    /**
     * Self-enrolment, and it is FREE COURSES ONLY (027 · FR-004).
     *
     * ⚠️ UNTIL SPEC 027 THIS GRANTED ANY AUTHENTICATED ACCOUNT ACTIVE ACCESS TO
     * ANY PUBLISHED COURSE ON THE PLATFORM, FREE. `CoursePolicy::view()` allows
     * every published course, and `belongsToCurrentWorkspace()` raises no
     * objection on a null context — which is every student, since a student is a
     * member of no workspace. `EnrollStudent` then writes `status = active`
     * directly. Register an account, harvest a uuid from the public marketplace,
     * POST here: curriculum, lessons and playback grants all open. It shipped
     * with zero callers under `frontend/src`, which is why nobody noticed.
     *
     * ⚠️ AND THE PREDICATE IS NOT `Course::isFree()`. That is `price_minor === 0`,
     * and `courses.price` is `->default(0)` and prices the ONE-OFF purchase alone
     * — so a course sold by subscription or by credits reads as free and the door
     * stays open for exactly the courses this feature exists to sell, with the
     * criterion measuring it green over the top. `courseRequiresPurchase()` asks
     * the price AND whether any sellable plan reaches the course.
     *
     * The route is not deleted: a genuinely free course is a real case, and it is
     * the only one that still passes.
     */
    public function enroll(
        Request $request,
        Course $course,
        EnrollStudent $action,
        SubscriptionDirectory $subscriptions,
    ): JsonResponse {
        $this->authorize('view', $course);

        if (! $course->isPublished()) {
            return response()->json(['message' => 'Course is not available for enrollment.'], 422);
        }

        if ($subscriptions->courseRequiresPurchase((int) $course->getKey())) {
            return response()->json([
                'message' => 'هذا الكورس يُشترَك فيه بطلبٍ معتمَد.',
                'code' => 'purchase_required',
            ], 422);
        }

        $enrollment = $action->handle($course, $this->currentUser($request));

        return response()->json(EnrollmentResource::make($enrollment), 201);
    }

    /**
     * The whole course as a curriculum: every item with its state, and a reason
     * on every closed one (`US1` · FR-008 · FR-009).
     *
     * ⚠️ THE ENROLMENT LOOKUP IS THE ENTIRE GUARD, and it has to be explicit.
     * `{course}` is resolved by implicit binding, and `WorkspaceScope` adds no
     * condition at all when the context is null — which it always is for a
     * student, who is a member of no workspace and whose `last_workspace_id` is
     * never written by anything on their path. So the binding resolves ANY
     * teacher's course by uuid, and only owning a row in `enrollments` for it
     * stands between a stranger and this payload.
     *
     * `403`, not `404`: the reader is authenticated and the course is one they
     * could buy — pretending it does not exist would break the buy button beside
     * the message.
     */
    public function curriculum(Request $request, Course $course, ReadCurriculum $action): JsonResponse
    {
        $enrollment = $this->enrolmentIn($request, $course);

        if ($enrollment === null) {
            return $this->notEnrolled();
        }

        $this->authorize('view', $enrollment);

        return response()->json(CurriculumResource::make($action->handle($enrollment)));
    }

    /**
     * What was said about this course, for the student it was said to (US2 ·
     * FR-019).
     *
     * The same enrolment guard as {@see curriculum()} and for the same reason: a
     * student has no workspace context, so the implicit `{course}` binding
     * resolves any teacher's course by uuid and nothing but a row in
     * `enrollments` stands in front of this payload.
     */
    public function announcements(Request $request, Course $course, ReadCourseAnnouncements $action): JsonResponse
    {
        $enrollment = $this->enrolmentIn($request, $course);

        if ($enrollment === null) {
            return $this->notEnrolled();
        }

        $this->authorize('view', $enrollment);

        return response()->json([
            'data' => CourseAnnouncementResource::collection($action->handle($course)),
        ]);
    }

    /**
     * The reader's own enrolment in this course, or null.
     *
     * ⚠️ ONE SPELLING FOR EVERY DOOR ONTO THE COURSE PAGE. Two endpoints asking
     * «is this course theirs» in two ways is the defect this repository keeps
     * paying for — one answer on the screen and another behind the button.
     */
    private function enrolmentIn(Request $request, Course $course): ?Enrollment
    {
        return Enrollment::query()
            ->where('course_id', $course->getKey())
            ->where('student_user_id', $this->currentUser($request)->getKey())
            ->first();
    }

    /**
     * `403`, not `404`: the reader is authenticated and the course is one they
     * could buy — pretending it does not exist would break the buy button beside
     * the message.
     */
    private function notEnrolled(): JsonResponse
    {
        return response()->json([
            'message' => 'لا تملك تسجيلاً في هذا الكورس.',
            'code' => LessonAccess::NOT_ENROLLED,
        ], 403);
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

        return response()->json($this->lessonPayload($lesson, $enrollment->accessTo($lesson), $enrollment));
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
        $viewer = $this->currentUser($request);

        $enrollment = Enrollment::query()
            ->where('course_id', $lesson->course_id)
            ->where('student_user_id', $viewer->getKey())
            ->first();

        if ($enrollment !== null) {
            $this->authorize('view', $enrollment);

            return response()->json($this->lessonPayload($lesson, $enrollment->accessTo($lesson), $enrollment));
        }

        /*
         * ⚠️ THE AUTHOR'S SIDE, AND WITHOUT IT THE TEACHER HAS NO PLAYER AT ALL.
         *
         * This is the only surface in the product that plays a lesson, and it
         * required an enrolment — which no teacher holds in their own workspace.
         * So the recording button on the teacher's own session page, the single
         * link to a published recording, answered «العنصر المطلوب غير موجود أو
         * حُذف» about a video they had just taught. `IssuePlaybackGrant::mayWatch`
         * had allowed them all along; the entitlement existed and the door did
         * not.
         *
         * Membership of the lesson's workspace is the same predicate that Action
         * uses for the author's branch, spelled here rather than called across
         * the module boundary (Constitution III). It is not a widening of the
         * student route: a student is not a workspace member — the only writers
         * of that pivot are `AcceptInvitation` and `CreateWorkspace`, so
         * enrolling never grants it.
         */
        if ($viewer->workspaces()->where('workspaces.id', $lesson->workspace_id)->exists()) {
            return response()->json($this->lessonPayload($lesson, LessonAccess::allow()));
        }

        return response()->json(['message' => 'لا تملك تسجيلاً في هذا الكورس.'], 404);
    }

    /**
     * What a student may see of one item.
     *
     * Shared by both entrances so the two cannot drift — the moment they do, one
     * of them is showing a field the other decided to withhold.
     *
     * @return array<string, mixed>
     */
    private function lessonPayload(Lesson $lesson, LessonAccess $access, ?Enrollment $enrollment = null): array
    {
        $lesson->load(['section', 'chapter', 'attachments']);

        // A draft or archived item answers 404, not a payload with a reason.
        //
        // The other refusals — sequence, exam gate — describe an item the student
        // will reach, so naming it is the help FR-043 asks for. An unfinished one
        // is different in kind: FR-025 allows zero draft fields in a student
        // payload, and even the title is a field. That a particular lesson exists
        // at this uuid is itself information about work the teacher has not
        // published.
        abort_if($access->code === LessonAccess::NOT_VISIBLE, 404, 'لا يوجد درس بهذا المعرّف.');

        $canAccess = $access->allowed;
        $type = LessonType::from($lesson->type);

        return [
            'lesson' => [
                'uuid' => $lesson->uuid,
                'title' => $lesson->title,
                'type' => $type->value,
                'type_label' => $type->label(),
                'is_completable' => LessonTypeRegistry::isCompletable($type),
                /*
                | ⚠️ الحقلانِ اللذانِ لم يكنْ لهما قارئ — وبلا الثاني لم يكنْ في
                | المنتَجِ كلِّه ما يُتِمُّ درساً. `POST …/lessons/{lesson}/complete`
                | قائمٌ منذُ ٠١٦، و`enrollment_uuid` أسفلَه يحملُ تعليقاً يقولُ
                | صراحةً إنّه «ما تستعملُه شاشةُ الطالبِ لتعليمِ العنصرِ مكتملاً» —
                | ولا ملفَّ واحدٍ في الواجهةِ ينادي ذلك المسار. فبقيَ «أتممتَ ٠ من
                | ٣ — ٠٪» الحالةَ الوحيدةَ التي يبلغُها طالبٌ في فيديو أو مقال،
                | و`CourseCompleted` لا يُطلَقُ أبداً فلا تصدرُ شهادةٌ إطلاقاً.
                | بلاغُ ٢٠٢٦-٠٩-٠٦.
                |
                | و`may_self_complete` من {@see LessonTypeRegistry} لا محسوبٌ هنا:
                | البابُ أدناه يرفضُ بالقاعدةِ نفسِها، وحقلٌ يُحسَبُ بهجاءٍ وبابٌ
                | يرفضُ بآخرَ هو عطبُ البابَينِ المختلفَين.
                */
                'may_self_complete' => LessonTypeRegistry::isSelfCompletable($type),
                'is_completed' => $enrollment !== null && LessonProgress::query()
                    ->where('enrollment_id', $enrollment->getKey())
                    ->where('lesson_id', $lesson->getKey())
                    ->where('status', 'completed')
                    ->exists(),
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
            // Null for the author's view: there is no enrolment behind it, and
            // the field is what a student's screen uses to mark an item complete.
            'enrollment_uuid' => $enrollment?->uuid,
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

        /*
         * ⚠️ الرفضُ هنا في المتحكِّمِ لا في الإجراء، وهو خروجٌ مقصودٌ عن قاعدةِ
         * «الحرسُ في الإجراء». الإجراءُ مشتركٌ مع
         * {@see CompleteExamLessonOnSubmission}، وهو الكاتبُ **الشرعيُّ** لعنصرِ
         * الاختبار — فرفضٌ غيرُ مشروطٍ هناك يكسرُ المستمِعَ نفسَه. والقاعدةُ
         * المُطبَّقةُ ليست «هل يُكمَلُ هذا العنصر» بل «أيُّ بابٍ يجوزُ له أن
         * يُعلِنَ الإكمال»، والبابُ هو هذا.
         *
         * ⚠️ وإخفاءُ الزرِّ ليس حرساً: المسارُ كانَ يقبلُ إتمامَ عنصرِ اختبارٍ
         * لأيِّ طالبٍ مسجَّلٍ بطلبٍ واحد — أي تخطّي الاختبارِ وتحريكُ النسبةِ بلا
         * إجابةِ سؤال.
         */
        if (! LessonTypeRegistry::isSelfCompletable(LessonType::from($lesson->type))) {
            return response()->json([
                'message' => 'يكتمل هذا العنصر بتسليم الاختبار نفسه، لا بتعليمه يدويّاً.',
                'code' => 'NOT_SELF_COMPLETABLE',
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
