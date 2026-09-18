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
use RuntimeException;

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
     * The stamp {@see self::priceReaches()} reads — `null` means «never asked».
     *
     * Not `$appends`, not a cast, and not in `$fillable`: it is not a column.
     */
    private ?bool $priceReaches = null;

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
     * Whether this group is structurally open to a new student — open, and with
     * a place.
     *
     * ⛔ IT IS NOT THE PICKER'S QUESTION AND MUST NEVER BE USED AS ONE
     * (٠٣٦ · FR-003). {@see self::isJoinable()} is that, and it adds the price.
     * This half exists because three doors ask a genuinely different question:
     * a paid order being approved, a transfer, and a waitlist invitation have
     * all already settled the money, so re-asking it there would repossess what
     * somebody bought.
     */
    public function isStructurallyJoinable(): bool
    {
        return $this->status === self::OPEN && ! $this->isFull();
    }

    /**
     * Whether a student could join THIS group at this instant.
     *
     * The screen and the door read the same predicate — two spellings of
     * "joinable" put one answer on the picker and another at the door.
     *
     * ⛔ NARROWED IN PLACE RATHER THAN RENAMED (٠٣٦ · T041), AND THE DIRECTION OF
     * FAILURE IS THE REASON. This name has four readers and two of them are money
     * doors; renaming it leaves each caller compiling against whichever name they
     * were written with, so a door that should have gained the price condition
     * simply keeps the old one — **failing open**. Narrowing the existing name
     * fails CLOSED: a caller that must not have the new condition has to say so,
     * in writing, by asking {@see self::isStructurallyJoinable()} instead.
     *
     * ⚠️ AND `scopeJoinable()` IS DELIBERATELY NOT NARROWED WITH IT. A query
     * scope cannot read a stamp, and a subquery on `plans` inside `Learning` is
     * forbidden by the contract that owns that table. It stays structural, says
     * so in its own docblock, and its single caller in the whole tree joins the
     * two halves itself.
     */
    public function isJoinable(): bool
    {
        return $this->isStructurallyJoinable() && $this->priceReaches();
    }

    /**
     * Whether a live price reaches this group — read from a STAMP, never asked.
     *
     * ⛔ AND THERE IS NO SILENT FALL-BACK TO A QUERY (٠٣٦ · T045). A read with no
     * stamp is a programming error and is raised as one. A fall-back would fire a
     * question about `plans` under whatever context the caller happens to be in —
     * a queue worker among them, where the workspace resolution is somebody
     * else's — and it would do it once per row inside a Resource, which is an
     * N+1 by construction. Two defects for the price of one convenience.
     *
     * The precedent is `WithholdingReader::stamp()`, and it is the same shape: a
     * bulk answer computed once by whoever knows the whole list, carried on the
     * rows.
     */
    public function priceReaches(): bool
    {
        if ($this->priceReaches === null) {
            throw new RuntimeException(
                'Cohort::priceReaches() was read without a stamp. Ask the cohort directory for these rows — '
                .'it stamps them in bulk — rather than reading a model straight out of a query.'
            );
        }

        return $this->priceReaches;
    }

    /**
     * Carry the bulk answer onto this row.
     *
     * ⚠️ NOT A COLUMN AND NOT AN ATTRIBUTE. It is derived from another module's
     * table and moves the instant an officer prices a plan or a teacher switches
     * one off; stored, it would be a second answer that drifts from the first.
     */
    public function stampPriceReach(bool $reached): static
    {
        $this->priceReaches = $reached;

        return $this;
    }

    /** Whether this row has been stamped at all — for a caller that has to know. */
    public function hasPriceStamp(): bool
    {
        return $this->priceReaches !== null;
    }

    /**
     * Whether ADMINISTRATION could put somebody in this group at this instant
     * (٠٣٤ · FR-030).
     *
     * ⚠️ A SECOND QUESTION, NOT A LOOSER SPELLING OF {@see isJoinable()}. A
     * `closed` group that is not full is a legitimate destination for a person
     * with authority and an illegal one for a student — `closed` says «no new
     * joins», which is a statement about the door rather than about the room, and
     * `CohortMembershipWriter::open()` has taken `requireOpen: false` for exactly
     * that since ٠٢١ · FR-028ط. So four readers were asking one name for two
     * questions, and the one that got the wrong answer was the picker: an officer
     * with a half-empty closed group was offered nothing.
     *
     * ⚠️ ARCHIVED IS REFUSED FOR BOTH, and the ceiling binds both — the writer
     * throws on either, so an «assignable» that ignored them would produce a
     * picker offering rows every write refuses.
     */
    public function isAssignable(): bool
    {
        return $this->status !== self::ARCHIVED && ! $this->isFull();
    }

    /**
     * @param  Builder<Cohort>  $query
     * @return Builder<Cohort>
     */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', self::ARCHIVED)
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('capacity')
                ->orWhereColumn('members_count', '<', 'capacity'));
    }

    /**
     * ⚠️ STRUCTURAL ONLY, AND DELIBERATELY NOT NARROWED WITH {@see self::isJoinable()}
     * (٠٣٦ · T041). A query scope cannot read a stamp, and a subquery on `plans`
     * from inside `Learning` is forbidden by the contract that owns that table —
     * `ContextIsolationTest` fails the build over it. It has exactly ONE caller in
     * the whole tree, `EloquentCohortDirectory::joinableCohortsExist()`, and that
     * caller joins the price half itself.
     *
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
