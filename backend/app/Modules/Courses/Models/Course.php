<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use App\Shared\Traits\IsPubliclyListed;
use App\Shared\Traits\IsPublishable;
use Database\Factories\Modules\Courses\CourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
        // Raised by every structural write, and sent back by the client on the
        // next one — an editor whose token is stale is looking at a tree that
        // has changed under them.
        'structure_version',
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
            'structure_version' => 'integer',
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
     * A course is publicly listed when it is published, explicitly public, and
     * its author has a public page.
     *
     * `visibility` is checked separately from isPublished() on purpose: a course
     * can be published to an academy's own students without being offered to the
     * whole marketplace, and conflating the two would publish the first kind.
     *
     * The author condition is the third, and it is not cosmetic. There is no
     * standalone course page: the card's title links to the AUTHOR's profile,
     * because that is where the course can actually be booked. So a course whose
     * author has no public page is an entry in the marketplace whose only
     * destination is a 404 — the visitor's first interaction with it is the
     * error. Dropping the byline and listing it anyway leaves a card that cannot
     * be clicked at all, which is a different way of publishing nothing.
     *
     * `isPubliclyListed()` below answers the same question row by row for the
     * search index. The two must move together.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function publicListingConstraints(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->whereExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('teacher_profiles')
                    ->whereColumn('teacher_profiles.user_id', 'courses.created_by')
                    ->where('teacher_profiles.is_publicly_listed', true)
                    // The constant rather than 'approved': a literal here is
                    // coupling to another module that nobody can grep for, and
                    // the two would drift the first time the value changed.
                    ->where('teacher_profiles.approval_status', TeacherProfile::STATUS_APPROVED);
            });
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
     * Used by the search index, which has no query to attach a scope to. It has
     * to stay in step with `publicListingConstraints()` above — including the
     * author condition, or search would surface exactly the courses the listing
     * refuses to show.
     */
    public function isPubliclyListed(): bool
    {
        $profile = $this->creator?->teacherProfile;

        return $this->isPublished()
            && $this->visibility === 'public'
            && (bool) $this->workspace?->participates_in_marketplace
            && $profile !== null
            && (bool) $profile->is_publicly_listed
            && $profile->approval_status === TeacherProfile::STATUS_APPROVED;
    }
}
