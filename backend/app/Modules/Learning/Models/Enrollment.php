<?php

declare(strict_types=1);

namespace App\Modules\Learning\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Courses\Support\ReferenceIntegrity;
use App\Modules\Learning\Support\LessonAccess;
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
     * Determines whether the student can access the given lesson, respecting
     * sequential gating and preview flags.
     *
     * Uses a targeted SQL query to find the immediately preceding lesson in course order,
     * then checks its completion status — avoids loading the full lesson rowset (which
     * includes heavy longText content) into memory.
     */
    public function canAccessLesson(Lesson $lesson): bool
    {
        return $this->accessTo($lesson)->allowed;
    }

    /**
     * The same decision, carrying its reason (FR-043).
     *
     * `canAccessLesson()` above stays a bool because a dozen call sites ask it
     * as one; this is the form the student's own screen needs, since "locked"
     * with nothing after it tells them nothing about what to go and do.
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

        if ($lesson->is_preview) {
            return LessonAccess::allow();
        }

        if (! $this->isActive()) {
            return LessonAccess::deny(
                LessonAccess::INACTIVE,
                'تسجيلك في هذا الكورس غير نشط حالياً.',
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

        $attempts = Attempt::query()
            ->where('exam_id', $previous['reference_id'])
            ->where('student_user_id', $this->student_user_id)
            ->whereNotNull('submitted_at');

        if ($gate === ExamGate::Pass) {
            $attempts->where('passed', true);
        }

        if ($attempts->exists()) {
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
