<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use App\Shared\Traits\IsPubliclyListed;
use App\Shared\Traits\IsPublishable;
use Database\Factories\Modules\Courses\CourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

/**
 * @property string $status
 * @property string $visibility
 * @property string $course_type
 * @property string|null $cover_path
 * @property string|null $price_before_discount
 * @property-read User|null $creator created_by is nullable — a course can outlive its author
 */
class Course extends BaseModel
{
    /** @use HasFactory<CourseFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid, IsPubliclyListed, IsPublishable, Searchable;

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_GROUP = 'group';

    public const TYPE_RECORDED = 'recorded';

    /** @return list<string> */
    public static function types(): array
    {
        return [self::TYPE_INDIVIDUAL, self::TYPE_GROUP, self::TYPE_RECORDED];
    }

    protected $fillable = [
        'workspace_id',
        'title',
        'slug',
        'description',
        'price',
        'currency',
        'status',
        'visibility',
        'is_sequential',
        'language',
        'duration_seconds',
        'created_by',
        'course_type',
        'cover_path',
        'price_before_discount',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'price_before_discount' => 'decimal:2',
            'is_sequential' => 'boolean',
            'duration_seconds' => 'integer',
        ];
    }

    /** @return HasMany<Section, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('order');
    }

    /** @return HasMany<Chapter, $this> */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('order');
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('order');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * A course is publicly listed when it is published *and* explicitly public.
     *
     * `visibility` is checked separately from isPublished() on purpose: a course
     * can be published to an academy's own students without being offered to the
     * whole marketplace, and conflating the two would publish the first kind.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function publicListingConstraints(Builder $query): Builder
    {
        return $query->where('status', 'published')->where('visibility', 'public');
    }

    public function isFree(): bool
    {
        return (float) $this->price === 0.0;
    }

    protected function searchableAs(): string
    {
        return 'courses_index';
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'price' => (float) $this->price,
            // Indexed, not filtered after the fact (R2): Scout runs outside every
            // global scope, so an unpublished course excluded only on the way out
            // would still consume a result slot and leak its title in the count.
            'is_publicly_listed' => $this->isPubliclyListed(),
        ];
    }

    /**
     * Row-level answer to the same question scopePubliclyListed() asks in SQL.
     *
     * Used by the search index, which has no query to attach a scope to.
     */
    public function isPubliclyListed(): bool
    {
        return $this->isPublished()
            && $this->visibility === 'public'
            && (bool) $this->workspace?->participates_in_marketplace;
    }
}
