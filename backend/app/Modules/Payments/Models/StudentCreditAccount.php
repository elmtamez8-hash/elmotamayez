<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One credit account per student, across every teacher they study with (FR-001).
 *
 * Platform-owned (constitution v1.2.0 §I, kind أ): deliberately NO
 * BelongsToWorkspace. Adding it would produce a duplicate account per teacher —
 * the mirror-image defect of a missing scope, and the one that shows up late,
 * after the duplicated rows have piled up. The guard is row ownership by the
 * user, plus the teacher-visibility rule (NFR-001أ).
 *
 * Created lazily. A student who has never bought has no row, and reading their
 * balance returns zero — which is what US1/1 asks for. The concurrent-creation
 * case is absorbed explicitly by catching the unique violation and re-reading,
 * in CreditAccounts, rather than trusting one framework version's behaviour.
 *
 * @property-read User $user user_id is NOT NULL
 */
class StudentCreditAccount extends BaseModel
{
    use HasUuid;

    protected $fillable = [
        'user_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CreditBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(CreditBalance::class);
    }
}
