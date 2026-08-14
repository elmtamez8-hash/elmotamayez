<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $uuid
 * @property PaymentStatus $status
 * @property ?PaymentMethod $method
 * @property int $amount_minor
 * @property string $provider
 * @property ?int $captured_order_id
 * @property int $order_id order_id is NOT NULL
 * @property string $currency
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $settled_at
 */
class PaymentTransaction extends BaseModel
{
    use BelongsToWorkspace;
    use HasUuid;

    protected $fillable = [
        'workspace_id',
        'order_id',
        'provider',
        'amount_minor',
        'currency',
        'status',
        'method',
        'reference',
        'failure_reason',
        'settled_at',
        'payload',
    ];

    /*
     * ⚠️ `captured_order_id` IS DELIBERATELY NOT FILLABLE. It is not a value a
     * caller supplies — it is written `= order_id` inside the same atomic
     * conditional UPDATE that moves the status to captured, and cleared to NULL
     * by ReversePayment. Mass-assignable, it becomes a second way to claim the
     * one-capture-per-order lock, from outside the transaction that owns it.
     */

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => PaymentStatus::class,
            'method' => PaymentMethod::class,
            'settled_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The price snapshot behind this payment, through the order it paid for.
     *
     * ⚠️ THIS ONE IS POSSIBLE AND `CreditPurchase::creditTransaction()` WAS NOT,
     * and the difference is which index the relation can carry. The hop here is
     * `order_id → order_id`, and `credit_purchases(order_id)` is a single-column
     * index the eager load's `WHERE order_id IN (…)` uses as it stands. The
     * ledger's key needs `credit_balance_id` leading, which no relation can put
     * in front of an eager load's own predicate — see the note on that model.
     *
     * ⚠️ AND EVERY EAGER LOAD OF IT DECLARES `withoutWorkspaceScope()`. Silence
     * there returns null for every row outside the reader's fallback workspace
     * and passes on a single-workspace fixture.
     *
     * @return HasOne<CreditPurchase, $this>
     */
    public function purchase(): HasOne
    {
        return $this->hasOne(CreditPurchase::class, 'order_id', 'order_id');
    }
}
