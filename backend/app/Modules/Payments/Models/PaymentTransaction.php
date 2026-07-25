<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $status
 * @property string $provider
 */
class PaymentTransaction extends BaseModel
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'order_id',
        'provider',
        'amount',
        'currency',
        'status',
        'reference',
        'payload',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
