<?php

declare(strict_types=1);

namespace App\Modules\Learning\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\LessonTypeRegistry;
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
        // A lesson from another course never counts towards this enrollment,
        // preview flag or not — otherwise progress could be driven to 100%
        // with lessons the student's course does not contain.
        if ($lesson->course_id !== $this->course_id) {
            return false;
        }

        if ($lesson->is_preview) {
            return true;
        }

        if (! $this->isActive()) {
            return false;
        }

        if (! $this->course->is_sequential) {
            return true;
        }

        $lessonSectionOrder = $lesson->section->order ?? 0;
        $lessonChapterOrder = $lesson->chapter->order ?? 0;

        $previousLessonId = DB::table('lessons')
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
            ->orderBy('lessons.order', 'desc')
            ->value('lessons.id');

        // If this is the first lesson, it's always accessible.
        if ($previousLessonId === null) {
            return true;
        }

        // The previous lesson must be completed.
        return $this->progress()
            ->where('lesson_id', $previousLessonId)
            ->where('status', 'completed')
            ->exists();
    }
}
