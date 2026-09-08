<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Marketplace\Models\SchoolYear;
use App\Modules\Marketplace\Support\SchoolYearDirectory;
use App\Shared\Support\GuardianPermission;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Identity\ParentStudentRelationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A guardian's standing relationship to one student.
 *
 * Platform-owned: no BelongsToWorkspace, by constitutional classification. The
 * relation describes two people, not an academy's data, and a copy per workspace
 * would give one family several consent records that drift apart.
 *
 * The consequence is that this table has no global scope at all — every read is
 * as exposed as an unauthenticated marketplace query. ParentStudentRelationPolicy
 * is the only thing standing between a teacher and another teacher's families.
 *
 * Timestamps restated: Larastan reads them as plain `timestamp` from the
 * migration and does not see casts().
 *
 * @property string $relation_type
 * @property string|null $student_grade_level_slug
 * @property string|null $student_school_year_slug
 * @property string $status
 * @property array<int, string> $permissions
 * @property int|null $requested_by_user_id
 * @property Carbon|null $accepted_at
 * @property int $live_slot
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $guardian
 */
class ParentStudentRelation extends BaseModel
{
    /** @use HasFactory<ParentStudentRelationFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'guardian_user_id',
        'student_user_id',
        'student_name',
        'student_age',
        'student_grade_level_slug',
        /*
        | Spec 022 · FR-005 — the child's individual year. `student_user_id` is
        | NULLABLE, so for a child with no account this row is the only place the
        | year is recorded at all; the stage column above stays as the fallback
        | for relations created before years existed.
        |
        | Fillable in the same change as the migration: `LinkGuardian` writes
        | through `create()`, and a non-fillable key is dropped in silence.
        */
        'student_school_year_slug',
        'relation_type',
        'permissions',
        'status',
        'revoked_at',
        /*
        | Spec 030 — WHO ASKED, and WHEN IT WAS ACCEPTED.
        |
        | ⛔ THESE TWO LINES ARE THE FEATURE. Both columns are written through
        | mass assignment — `LinkGuardian` via `create()` and `RegisterStudent`
        | via `firstOrCreate()` — and mass assignment DISCARDS A NON-FILLABLE KEY
        | IN SILENCE: no exception, no log, a 201. Left out, every new relation
        | carries `requested_by_user_id = NULL`, which `decidableBy()` reads as
        | "nobody may decide this", and the whole phase ships dead with every
        | endpoint answering 200.
        |
        | The rule is already written one field above, for `student_school_year_slug`.
        | It has still shipped three times on `student_profiles` in one change,
        | with every assertion green — because they were made against a response
        | body, which echoes what was SUBMITTED rather than what was stored.
        */
        'requested_by_user_id',
        'accepted_at',
        /*
        | ⚠️ AND `live_slot` IS DELIBERATELY ABSENT FROM THIS LIST. It is the
        | unique index's sentinel (`0` while live, the row id once revoked) and it
        | is claimed inside the same write that ends the relation. Mass-assignable
        | it becomes a second way to free the pair from outside that write, which
        | is exactly why `captured_order_id` is not fillable either.
        */
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'student_age' => 'integer',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function relationType(): RelationType
    {
        return RelationType::from($this->relation_type);
    }

    public function status(): RelationStatus
    {
        return RelationStatus::from($this->status);
    }

    public function isActive(): bool
    {
        return $this->status === RelationStatus::Active->value;
    }

    public function allows(GuardianPermission $permission): bool
    {
        return in_array($permission->value, $this->permissions, true);
    }

    /**
     * May this person settle this pending link? (Spec 030 · FR-002.)
     *
     * ⚠️ ONE SPELLING, READ BY THREE CALLERS: the policy's `accept` ability (the
     * 403 shape), `AcceptRelation` itself, and the Resource's `can_decide`. The
     * first draft of this feature wrote the condition out three times and dropped
     * `requested_by_user_id !== null` from the Resource's copy — so an old row
     * rendered an accept button that the door answered 403. That is the
     * two-spellings defect wearing the costume of its own fix, the same one
     * `cohort_gate` and `BookingEligibility` each paid for.
     *
     * ⚠️ AND THE RESOURCE CALLS THIS, NOT `Gate::allows('accept', …)`. The gate
     * waves a super admin past every policy method, so through it the one actor
     * the Action refuses would be shown the button.
     *
     * «The party who did not ask» rather than «the student», because `pending`
     * runs in two directions: `LinkGuardian` asks and the student answers;
     * `RegisterStudent::inviteGuardian` asks on the student's behalf and the
     * guardian answers. A NULL requester is a row created before this column
     * existed — nobody can prove who asked, and assuming is inventing a consent.
     */
    public function decidableBy(User $user): bool
    {
        return $this->status === RelationStatus::Pending->value && $this->wasAskedOf($user);
    }

    /**
     * Is this person the party the link was put to — whatever state it is in now?
     *
     * ⚠️ THE POLICY ASKS THIS AND THE ACTION ASKS `decidableBy()`, AND THE SPLIT IS
     * DELIBERATE. Asking the narrower question at the door answers 403 to the very
     * person who accepted the link when they refresh — and to the student who just
     * refused it, in place of the sentence that tells them it has ended. So the
     * policy decides WHO MAY ASK and the Action decides WHAT HAPPENS.
     *
     * `can_decide` on the payload stays the narrow one: it draws a button.
     */
    public function wasAskedOf(User $user): bool
    {
        if ($this->requested_by_user_id === null
            || (int) $this->requested_by_user_id === (int) $user->getKey()) {
            return false;
        }

        return (int) $user->getKey() === (int) $this->guardian_user_id
            || (int) $user->getKey() === (int) $this->student_user_id;
    }

    /**
     * The child's broad stage — the same one derivation the student profile uses.
     *
     * ⚠️ IT TAKES TWO STRINGS AND NOT A PROFILE, WHICH IS WHY IT CAN LIVE HERE
     * AT ALL: `student_user_id` is nullable, so a child added by a guardian may
     * have no `StudentProfile` to ask.
     *
     * Per-row. `ParentStudentRelationResource` renders a COLLECTION and reads
     * {@see SchoolYearDirectory} instead.
     */
    public function stageSlug(): ?string
    {
        return SchoolYear::stageFor(
            $this->student_school_year_slug,
            $this->student_grade_level_slug,
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RelationStatus::Active->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForStudent(Builder $query, User $student): Builder
    {
        return $query->where('student_user_id', $student->getKey());
    }

    /** @return BelongsTo<User, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }
}
