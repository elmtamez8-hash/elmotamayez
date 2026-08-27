<?php

declare(strict_types=1);

namespace App\Modules\Learning\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Learning\CohortMembershipEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * What happened, when, at whose hand and why (FR-034). Append only.
 *
 * ⚠️ THE REFUSAL IS ON THE MODEL, NOT ONLY IN THE ACTION — the precedent is
 * `LedgerEntry`. An Action guards the one door it is; `booted()` guards every
 * caller, including a seeder and the panel.
 *
 * ⚠️ AND THEREFORE NO MASS WRITE EVER TOUCHES THIS TABLE. A bulk `update()`
 * retrieves no models, so it walks straight past the hooks below — the guard
 * would be present, tested, and absent exactly where it was needed.
 *
 * @property string $event
 */
class CohortMembershipEvent extends BaseModel
{
    /** @use HasFactory<CohortMembershipEventFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    public const UPDATED_AT = null;

    public const JOINED = 'joined';

    public const TRANSFERRED = 'transferred';

    public const LEFT = 'left';

    public const REMOVED = 'removed';

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    /** ⚠️ A rejection is recorded exactly as an approval is (FR-033). */
    public const REJECTED = 'rejected';

    /** A pending request the student can no longer act on — see `DropPendingRequest`. */
    public const REQUEST_DROPPED = 'request_dropped';

    protected $fillable = [
        'workspace_id',
        'course_id',
        'student_user_id',
        'cohort_id',
        'from_cohort_id',
        'event',
        'actor_user_id',
        'reason',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('سجل العضوية لا يُعدَّل — التصحيح بحدث جديد.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('سجل العضوية لا يُحذف — التصحيح بحدث جديد.');
        });
    }

    /** @return BelongsTo<Cohort, $this> */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /** @return BelongsTo<Cohort, $this> */
    public function fromCohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class, 'from_cohort_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }
}
