<?php

declare(strict_types=1);

namespace App\Modules\Learning\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Learning\CohortFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A scheduled run of one course.
 *
 * @property string $status
 * @property int|null $capacity
 * @property int $members_count
 * @property int|null $individual_for_user_id
 * @property-read Course $course
 */
class Cohort extends BaseModel
{
    /** @use HasFactory<CohortFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    public const OPEN = 'open';

    /** Existing members keep everything; nobody new may join. */
    public const CLOSED = 'closed';

    /** Terminal. There is no delete (FR-035). */
    public const ARCHIVED = 'archived';

    /*
    | ⚠️ `members_count` AND `status` ARE NOT FILLABLE, FOR TWO DIFFERENT REASONS.
    |
    | The counter is claimed inside the atomic conditional UPDATE that owns the
    | seat; mass-assignable, it becomes a second way to move a number the claim
    | is the only correct writer of. `status` moves through `ArchiveCohort` and
    | `UpdateCohort`, which refuse to walk a terminal row backwards — a PATCH
    | carrying `status: open` would otherwise un-archive a group from outside the
    | Action that owns the transition.
    */
    protected $fillable = [
        'workspace_id',
        'course_id',
        'name',
        'description',
        'capacity',
        'created_by',
        'individual_for_user_id',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'members_count' => 'integer',
            'individual_for_user_id' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CohortMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(CohortMembership::class);
    }

    /**
     * Full right now.
     *
     * ⚠️ DERIVED FROM TWO COLUMNS THAT ARE ALREADY HERE, never stored. And a
     * group with no declared capacity is never full — `null` is "no ceiling",
     * which is a different promise from a large number.
     */
    public function isFull(): bool
    {
        return $this->capacity !== null && $this->members_count >= $this->capacity;
    }

    /** How many are left, or `null` when no ceiling was declared — never zero. */
    public function seatsLeft(): ?int
    {
        return $this->capacity === null ? null : max(0, $this->capacity - $this->members_count);
    }

    /**
     * Whether a student could join THIS group at this instant.
     *
     * The screen and the safety valve read the same predicate — two spellings of
     * "joinable" put one answer on the picker and another at the door.
     */
    public function isJoinable(): bool
    {
        return $this->status === self::OPEN && ! $this->isFull();
    }

    /**
     * @param  Builder<Cohort>  $query
     * @return Builder<Cohort>
     */
    public function scopeJoinable(Builder $query): Builder
    {
        return $query
            ->where('status', self::OPEN)
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('capacity')
                ->orWhereColumn('members_count', '<', 'capacity'));
    }

    /** One person's private group — a 1:1 lesson wearing the shape of a group. */
    public function isIndividual(): bool
    {
        return $this->individual_for_user_id !== null;
    }

    /**
     * Ordinary groups: the ones a course offers to whoever enrols.
     *
     * ⚠️ THE PUBLIC READ FILTERS ON THIS, NEVER ON THE STATUS. A private group
     * is created `closed` and so is invisible today for a reason that has
     * nothing to do with whose it is — filter by status and the first day one is
     * opened for any reason at all, its owner's name is on the marketplace.
     * `PublicCohortsTest` opens one deliberately and demands it stay absent.
     *
     * @param  Builder<Cohort>  $query
     * @return Builder<Cohort>
     */
    public function scopeGroup(Builder $query): Builder
    {
        return $query->whereNull('individual_for_user_id');
    }

    /**
     * @param  Builder<Cohort>  $query
     * @return Builder<Cohort>
     */
    public function scopeIndividual(Builder $query): Builder
    {
        return $query->whereNotNull('individual_for_user_id');
    }
}
