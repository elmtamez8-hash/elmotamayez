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
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'student_age' => 'integer',
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
