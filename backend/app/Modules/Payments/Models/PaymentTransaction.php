<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uuid
 * @property PaymentStatus $status
 * @property ?PaymentMethod $method
 * @property int $amount_minor
 * @property string $provider
 * @property ?int $captured_order_id
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
}
