<?php

declare(strict_types=1);

namespace App\Modules\Learning\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Learning\EnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property string $status
 * @property string $source
 */
class Enrollment extends BaseModel
{
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

    protected function casts(): array
    {
        return [
            'progress_pct' => 'integer',
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

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

    protected static function newFactory(): Factory
    {
        return EnrollmentFactory::new();
    }
}
