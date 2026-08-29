<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Identity\Support\ReferralStatus;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Who invited whom, and whether it ever paid (spec 011 · FR-019 · FR-022).
 *
 * ⚠️ NO `BelongsToWorkspace` — see {@see ReferralCode}. And `status` is NOT
 * `$fillable`: every transition is a conditional UPDATE carrying the status it
 * expects, which is both the check and the claim. A mass-assignable status is a
 * second way to complete a referral from outside the transaction that owns it —
 * the `captured_order_id` rule.
 *
 * @property int $referrer_user_id
 * @property int $referred_user_id
 * @property ReferralStatus $status
 * @property Carbon|null $completed_at
 * @property Carbon|null $reversed_at
 * @property string|null $flagged_reason
 */
class Referral extends BaseModel
{
    use HasUuid;

    protected $fillable = ['referrer_user_id', 'referred_user_id', 'flagged_reason'];

    /**
     * ⚠️ DECLARED SO A ROW CREATED WITHOUT ONE READS `pending` IN MEMORY.
     * Eloquent does not read a column default back after an insert, so
     * `$referral->status->value` on a freshly created model would be a fatal on
     * null — the defect `Shipment` shipped with in Phase 3.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'pending'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'completed_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}
