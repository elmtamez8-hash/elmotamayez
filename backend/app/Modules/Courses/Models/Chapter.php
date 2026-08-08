<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Support\HasSiblingOrder;
use App\Modules\Courses\Support\OrdersSiblings;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Courses\ChapterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ContentStatus $status
 */
class Chapter extends BaseModel implements OrdersSiblings
{
    /** @use HasFactory<ChapterFactory> */
    use BelongsToWorkspace, HasFactory, HasSiblingOrder, HasUuid;

    protected $table = 'course_chapters';

    public function siblingScopeColumn(): string
    {
        return 'section_id';
    }

    protected $fillable = [
        'workspace_id',
        'section_id',
        'course_id',
        'title',
        'status',
        'order',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'order' => 'integer',
        ];
    }

    /**
     * @param  Builder<Chapter>  $query
     * @return Builder<Chapter>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::Published);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('order');
    }
}
