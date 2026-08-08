<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Support\HasSiblingOrder;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Courses\Support\OrdersSiblings;
use App\Modules\Media\Models\MediaAsset;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Courses\LessonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @property string $type
 * @property ContentStatus $status
 * @property int|null $reference_id
 * @property string|null $external_url
 * @property int|null $class_session_id
 */
class Lesson extends BaseModel implements OrdersSiblings
{
    /** @use HasFactory<LessonFactory> */
    use BelongsToWorkspace, HasFactory, HasSiblingOrder, HasUuid;

    public function siblingScopeColumn(): string
    {
        return 'chapter_id';
    }

    protected $fillable = [
        'workspace_id',
        'course_id',
        'section_id',
        'chapter_id',
        // Set when this lesson was published from a live session's recording.
        // Its presence changes who may watch: entitlement comes from a seat in
        // that session, not from enrolment in the course (FR-030).
        'class_session_id',
        'title',
        'type',
        'status',
        'content',
        'external_url',
        // The exam or session this item places. Interpreted by `type` — there is
        // deliberately no second column saying which, because two columns
        // recording one fact can disagree.
        'reference_id',
        'order',
        'duration_seconds',
        'is_preview',
        'is_free',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'order' => 'integer',
            'duration_seconds' => 'integer',
            'is_preview' => 'boolean',
            'is_free' => 'boolean',
        ];
    }

    /**
     * Items a student may see: published, inside a published chapter, inside a
     * published section.
     *
     * The chain matters (FR-028). A published lesson in a draft section is
     * hidden, and the teacher is shown that reason rather than the lesson's own
     * state — "published but blocked by its section" is a different problem to
     * solve than "still a draft".
     *
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    public function scopeVisibleToStudents(Builder $query): Builder
    {
        return $query
            ->where('lessons.status', ContentStatus::Published)
            ->whereHas('chapter', fn (Builder $q) => $q->where('status', ContentStatus::Published))
            ->whereHas('section', fn (Builder $q) => $q->where('status', ContentStatus::Published));
    }

    /**
     * The items a course's completion percentage is measured against.
     *
     * Three exclusions, and the third is the one that matters most:
     *
     * 1. Anything not published — a draft is not work the student has failed to
     *    do.
     * 2. Types that cannot be completed (`note`, `link`) — nothing is asked of
     *    the reader, so nothing can be marked done.
     * 3. **Session recordings.** A recording is entitled by holding a SEAT in
     *    that session, not by enrolment in the course (005 FR-030). Leaving it
     *    in the denominator gives every enrolled student without a seat a lesson
     *    they can never open: they never reach 100%, `shouldCompleteCourse()`
     *    never fires, and their certificate never issues. Not eventually —
     *    never. That is true of the code as shipped, and it is what this scope
     *    exists to fix.
     *
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    public function scopeCountableForProgress(Builder $query): Builder
    {
        return $query
            ->where('lessons.status', ContentStatus::Published)
            ->whereIn('lessons.type', LessonTypeRegistry::completableValues())
            ->whereNull('lessons.class_session_id');
    }

    /**
     * The lesson's video, if it has one.
     *
     * Replaces the old `media` JSON column, which held a path on the public disk
     * — a permanent link that worked forever for anyone who copied it.
     *
     * @return MorphOne<MediaAsset, $this>
     */
    public function mediaAsset(): MorphOne
    {
        return $this->morphOne(MediaAsset::class, 'owner');
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<Chapter, $this> */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
