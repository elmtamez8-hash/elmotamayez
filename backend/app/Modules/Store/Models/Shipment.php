<?php

declare(strict_types=1);

namespace App\Modules\Store\Models;

use App\Models\BaseModel;
use App\Modules\Store\Enums\ShipmentStatus;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Store\ShipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a printed book is going.
 *
 * ⚠️ THE ADDRESS IS A SNAPSHOT, NOT A JOIN. A student who moves house in
 * November must not rewrite the destination of a parcel posted in September —
 * the same reason `billable_seats` is frozen rather than recomputed.
 *
 * ⚠️ `status` IS NOT `$fillable`. Every change is a conditional
 * `WHERE status = :expected` inside `AdvanceShipment`, and every change notifies
 * the buyer: read-then-write from two workers tells them twice, or moves the
 * parcel backwards — «تم الشحن» after «وصل», which is a message nobody can
 * un-send.
 *
 * @property ShipmentStatus $status
 * @property CarbonInterface|null $status_changed_at
 */
class Shipment extends BaseModel
{
    /** @use HasFactory<ShipmentFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    /**
     * ⚠️ THE DEFAULT IS DECLARED TWICE ON PURPOSE — here and on the column.
     *
     * `status` is not `$fillable`, so `create()` never sets it and Eloquent does
     * not read the column default back: the row in the database says `pending`
     * while the object just returned says `null`, and the very next line —
     * `$shipment->status->value` — is a fatal on a write that succeeded. The
     * column default is for raw inserts, this one is for the model.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'workspace_id',
        'store_order_id',
        'recipient_name',
        'phone',
        'address_line',
        'notes',
        'tracking_ref',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StoreOrder, $this> */
    public function storeOrder(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class);
    }
}
