<?php

declare(strict_types=1);

namespace App\Modules\Learning\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Courses\Support\ReferenceIntegrity;
use App\Modules\Learning\Support\ExamGateSatisfaction;
use App\Modules\Learning\Support\LessonAccess;
use App\Shared\Contracts\SessionAttendanceDirectory;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Learning\EnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property string $status
 * @property string $source
 * @property-read Course $course course_id is NOT NULL, so the relation always resolves
 * @property-read User $student
 */
class Enrollment extends BaseModel
{
    /** @use HasFactory<EnrollmentFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'course_id',
        'student_user_id',
        'source',
        'order_id',
        'status',
        'progress_pct',
        'enrolled_at',
        'completed_at',
        'expires_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'progress_pct' => 'integer',
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return HasMany<LessonProgress, $this> */
    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Returns all lessons of the course ordered by section → chapter → lesson order.
     *
     * @return Collection<int, Lesson>
     */
    public function orderedLessons(): Collection
    {
        return $this->course->lessons()
            ->join('course_sections', 'lessons.section_id', '=', 'course_sections.id')
            ->join('course_chapters', 'lessons.chapter_id', '=', 'course_chapters.id')
            ->orderBy('course_sections.order')
            ->orderBy('course_chapters.order')
            ->orderBy('lessons.order')
            ->select('lessons.*')
            ->get();
    }

    /**
     * Whether the student can open this item — the bool form.
     *
     * Kept because a dozen call sites ask it as one. The reasoning, and the
     * documentation of how it is answered, live on `accessTo()` below.
     */
    public function canAccessLesson(Lesson $lesson): bool
    {
        return $this->accessTo($lesson)->allowed;
    }

    /**
     * The same decision, carrying its reason (FR-043).
     *
     * "Locked" with nothing after it tells the student nothing about what to go
     * and do — and an exam gate is invisible from the locked item, because what
     * has to happen is on another page.
     *
     * Finds the immediately preceding lesson with one targeted query and a column
     * list rather than loading the rowset: `content` is a longText and this runs
     * on every lesson open.
     */
    public function accessTo(Lesson $lesson): LessonAccess
    {
        // A lesson from another course never counts towards this enrollment,
        // preview flag or not — otherwise progress could be driven to 100%
        // with lessons the student's course does not contain.
        if ($lesson->course_id !== $this->course_id) {
            return LessonAccess::deny(
                LessonAccess::NOT_ENROLLED,
                'هذا الدرس ليس من الكورس المسجَّل فيه.',
            );
        }

        // What the student is opening has to be visible in its own right, and
        // this is checked BEFORE the preview flag — a preview lesson pulled back
        // to draft is still a draft.
        //
        // `visibleToStudents` was the only place `status` was read on the item
        // being fetched, and it guards the two LIST endpoints. The three status
        // conditions further down apply to the PREVIOUS lesson, in the
        // prerequisite query. So `/learn/lessons/{lesson}`, the endpoint that
        // carries the actual body, never asked — and `HasUuid` resolves a route
        // parameter by uuid OR id, so the ids could simply be walked until one
        // landed in the student's own course. It also meant a teacher withdrawing
        // a published lesson to revise it kept serving the old body, and a
        // student could still complete an archived item — which then made it
        // undeletable through TreeDeletionGuard.
        if (! $lesson->isVisibleChain()) {
            return LessonAccess::deny(
                LessonAccess::NOT_VISIBLE,
                'هذا الدرس غير متاح حالياً.',
            );
        }

        if ($lesson->is_preview) {
            return LessonAccess::allow();
        }

        if (! $this->isActive()) {
            return LessonAccess::deny(
                LessonAccess::INACTIVE,
                'تسجيلك في هذا الكورس غير نشط حالياً.',
            );
        }

        /*
         * ⚠️ A RECORDING IS ENTITLED BY THE SEAT, AND THE SEQUENCE MUST NOT ASK
         * A SECOND QUESTION IN FRONT OF IT.
         *
         * The prerequisite query below already refuses to let a recording STAND
         * in front of anything — the forever-bug that would lock a whole course
         * behind an hour the student was never in. This is the mirror image, and
         * it was missing: the recording ITSELF was gated on finishing unrelated
         * coursework, so on 2026-08-18 a student who had booked and paid for a
         * live session was told «أكمِل … أولاً» about the lesson that session had
         * just produced. Its only entrance is this endpoint, so the recording was
         * unreachable — while `IssuePlaybackGrant::mayWatch()`, the door the file
         * is actually served through, said yes.
         *
         * Two doors disagreeing is the defect; the seat is which one is right.
         * Asked through the shared directory rather than LiveSessions' models,
         * so the rule has one implementation and Constitution III holds.
         */
        if ($lesson->class_session_id !== null) {
            return app(SessionAttendanceDirectory::class)
                ->hasBookingForLesson($this->student, (int) $lesson->getKey())
                ? LessonAccess::allow()
                : LessonAccess::deny(
                    LessonAccess::NO_SEAT,
                    'هذا تسجيل حصة لم تحجز فيها مقعداً.',
                );
        }

        if (! $this->course->is_sequential) {
            return LessonAccess::allow();
        }

        $lessonSectionOrder = $lesson->section->order ?? 0;
        $lessonChapterOrder = $lesson->chapter->order ?? 0;

        $query = DB::table('lessons')
            ->join('course_sections', 'lessons.section_id', '=', 'course_sections.id')
            ->join('course_chapters', 'lessons.chapter_id', '=', 'course_chapters.id')
            ->where('lessons.course_id', $this->course_id)
            ->where('lessons.workspace_id', $this->workspace_id)
            // Only a lesson the student can actually see and finish may stand in
            // front of the next one.
            //
            // A draft is work nobody has been asked to do, and its whole chain
            // has to be published for it to be visible at all (FR-027).
            //
            // A recording is the sharper case (FR-027أ). It is entitled by a SEAT
            // in that session, not by enrolment, and since 016 it lands where the
            // teacher placed the session — mid-tree. Left as a prerequisite it
            // would lock everything after it, permanently, for every student who
            // was not in that room. That is the same forever-bug the progress
            // denominator had, arriving through the ordering instead.
            ->whereNull('lessons.class_session_id')
            ->where('lessons.status', ContentStatus::Published->value)
            ->where('course_chapters.status', ContentStatus::Published->value)
            ->where('course_sections.status', ContentStatus::Published->value)
            ->whereIn('lessons.type', LessonTypeRegistry::completableValues())
            ->where(function ($q) use ($lessonSectionOrder, $lessonChapterOrder, $lesson) {
                $q->where('course_sections.order', '<', $lessonSectionOrder)
                    ->orWhere(function ($q2) use ($lessonSectionOrder, $lessonChapterOrder) {
                        $q2->where('course_sections.order', $lessonSectionOrder)
                            ->where('course_chapters.order', '<', $lessonChapterOrder);
                    })
                    ->orWhere(function ($q3) use ($lessonSectionOrder, $lessonChapterOrder, $lesson) {
                        $q3->where('course_sections.order', $lessonSectionOrder)
                            ->where('course_chapters.order', $lessonChapterOrder)
                            ->where('lessons.order', '<', $lesson->order);
                    });
            })
            ->orderBy('course_sections.order', 'desc')
            ->orderBy('course_chapters.order', 'desc')
            ->orderBy('lessons.order', 'desc');

        // A reference item whose exam was deleted must not stand in front of
        // anything: nobody can sit an exam that is gone, so it would lock the
        // rest of the course permanently (FR-045).
        ReferenceIntegrity::apply($query);

        // More than the id now — the gate reads the previous item's type and,
        // for an exam, which of the two conditions it was given. Still a column
        // list rather than the model: `content` is a longText and this runs on
        // every lesson open.
        $row = $query
            ->select('lessons.id', 'lessons.title', 'lessons.type', 'lessons.reference_id', 'lessons.exam_gate')
            ->first();

        // If this is the first lesson, it's always accessible.
        if ($row === null) {
            return LessonAccess::allow();
        }

        $previous = (array) $row;
        $title = (string) $previous['title'];

        if ($previous['type'] === LessonType::Exam->value) {
            return $this->examGateFor($previous, $title);
        }

        $completed = $this->progress()
            ->where('lesson_id', $previous['id'])
            ->where('status', 'completed')
            ->exists();

        return $completed
            ? LessonAccess::allow()
            : LessonAccess::deny(
                LessonAccess::SEQUENCE,
                "أكمِل «{$title}» أولاً — هذا الكورس متسلسل.",
                $title,
            );
    }

    /**
     * The exam item standing in front of this one, and what it asks (FR-042).
     *
     * Read from the ATTEMPTS, not from a `lesson_progress` row. The two answer
     * different questions and can disagree: a student may have passed the exam
     * from the exam's own page before the teacher ever placed it in the tree,
     * and refusing them on the grounds that a progress row is missing would be
     * refusing them over our bookkeeping rather than over their work.
     *
     * "Attempted" means SUBMITTED. A started-and-abandoned attempt is a row that
     * exists because the student opened the page; treating it as a pass through
     * the gate would make the weaker gate no gate at all.
     *
     * @param  array<string, mixed>  $previous
     */
    private function examGateFor(array $previous, string $title): LessonAccess
    {
        $gate = ExamGate::tryFrom((string) ($previous['exam_gate'] ?? '')) ?? ExamGate::Attempt;

        // The predicate itself lives in one place, shared with the two writers of
        // the item's progress row. Three copies of "attempted" would be three
        // definitions, and the first to drift decides whether a course can be
        // finished at all.
        if (ExamGateSatisfaction::metBy((int) $previous['reference_id'], $gate, (int) $this->student_user_id)) {
            return LessonAccess::allow();
        }

        return $gate === ExamGate::Pass
            ? LessonAccess::deny(
                LessonAccess::EXAM_PASS,
                "لا يُفتح ما بعد «{$title}» حتى تجتاز الاختبار بالدرجة المطلوبة. أعِد المحاولة من صفحة الاختبار.",
                $title,
            )
            : LessonAccess::deny(
                LessonAccess::EXAM_ATTEMPT,
                "أدِّ اختبار «{$title}» وسلّم إجابتك ليُفتح ما بعده — الدرجة لا تحجبك.",
                $title,
            );
    }
}
