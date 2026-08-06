<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Modules\Media\Models\MediaAsset;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Courses\LessonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @property string $type
 */
class Lesson extends BaseModel
{
    /** @use HasFactory<LessonFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

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
        'content',
        'order',
        'duration_seconds',
        'is_preview',
        'is_free',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'duration_seconds' => 'integer',
            'is_preview' => 'boolean',
            'is_free' => 'boolean',
        ];
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
