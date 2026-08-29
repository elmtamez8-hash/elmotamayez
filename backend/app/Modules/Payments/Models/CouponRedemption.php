<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of one coupon on one order (spec 011 · FR-015).
 *
 * A bridge row, and the bridge is the record: it names the user, the order and
 * the amount taken off, which is the whole of what FR-015 asks for.
 *
 * ⚠️ NO `BelongsToWorkspace`, AND THE COLUMN IS ASSIGNED IN THE ACTION. The
 * trait fills it only when the workspace context is non-null, and every path
 * that writes this table is a BUYER's — a student, who is a member of no
 * workspace at all. The trait would write nothing, on every row, in silence.
 * The value comes from the order being paid for. Precedent: `credit_balances`,
 * and now `store_orders` and `shipments` beside it.
 *
 * @property int $workspace_id
 * @property int $coupon_id
 * @property int $user_id
 * @property int $order_id
 * @property int $discount_minor
 */
class CouponRedemption extends BaseModel
{
    use HasUuid;

    protected $fillable = [
        'workspace_id',
        'coupon_id',
        'user_id',
        'order_id',
        'discount_minor',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'discount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
