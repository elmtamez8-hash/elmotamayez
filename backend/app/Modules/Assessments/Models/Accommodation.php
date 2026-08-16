<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Assessments\Support\ApplyAccommodation;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing arrangement for one student: more time, and more days.
 *
 * ⚠️ IT APPLIES BY ITSELF OR IT IS NOT AN ACCOMMODATION (FR-054). A teacher who
 * has to remember it on every paper will forget it on the paper that mattered,
 * and the student will discover the omission from a mark. So nothing here is
 * "applied" by an operator: {@see ApplyAccommodation}
 * is consulted by the deadline computer and by the attempt issuer, every time.
 *
 * ⚠️ AND ITS EXISTENCE IS PRIVATE (FR-056). It is not in any list a classmate
 * reads, and the fields it moves — a deadline, a duration — are on the student's
 * own payload only. A shared payload that quietly differs per reader is the same
 * leak wearing a subtler face.
 *
 * @property int $extra_time_pct
 * @property int $extended_days
 */
class Accommodation extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    protected $fillable = [
        'workspace_id',
        'student_user_id',
        'extra_time_pct',
        'extended_days',
        'reason',
        'granted_by',
        'revoked_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'extra_time_pct' => 'integer',
            'extended_days' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * @param  Builder<Accommodation>  $query
     * @return Builder<Accommodation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
