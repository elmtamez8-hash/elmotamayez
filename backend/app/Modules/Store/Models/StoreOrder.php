<?php

declare(strict_types=1);

namespace App\Modules\Store\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Actions\RefundStorePurchase;
use App\Modules\Store\Enums\ShipmentStatus;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Store\StoreOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One purchase from the store — the bridge between an `orders` row and the item.
 *
 * A bridge row (Constitution I): it carries `workspace_id` for context and
 * points at the one platform-wide student, who buys from as many teachers as
 * they like.
 *
 * ⚠️ `workspace_id` IS ASSIGNED EXPLICITLY BY THE ACTION. `BelongsToWorkspace`
 * fills it only `if ($workspaceId !== null)`, and a student is a member of no
 * workspace at all — so on the buyer's path the context is null and the trait
 * writes nothing, silently. Precedent, in the same words: `credit_balances`.
 *
 * ⚠️ AND NEITHER `fulfilled_at` NOR `first_accessed_at` IS `$fillable`. Each is
 * claimed by the conditional UPDATE that owns its transition — the
 * `captured_order_id` rule. Fulfilment is `WHERE fulfilled_at IS NULL`, which is
 * what makes a redelivered `PaymentApproved` a no-op instead of a second stock
 * decrement; `first_accessed_at` closes the refund window and must be written
 * once however many grants are minted at the same instant.
 *
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $discount_minor
 * @property int $shipping_minor
 * @property int $commission_minor
 * @property int $teacher_net_minor
 * @property CarbonInterface|null $fulfilled_at
 * @property CarbonInterface|null $first_accessed_at
 * @property CarbonInterface|null $refunded_at
 */
class StoreOrder extends BaseModel
{
    /** @use HasFactory<StoreOrderFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'order_id',
        'store_item_id',
        'buyer_user_id',
        'quantity',
        'unit_price_minor',
        'discount_minor',
        'shipping_minor',
        'commission_minor',
        'teacher_net_minor',
        'currency',
        'refunded_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'shipping_minor' => 'integer',
            'commission_minor' => 'integer',
            'teacher_net_minor' => 'integer',
            'fulfilled_at' => 'datetime',
            'first_accessed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<StoreItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'store_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    /**
     * ⚠️ UNSCOPED, because a buyer reads it. `Shipment` carries
     * `BelongsToWorkspace`, and a student a teacher once added to ANOTHER
     * workspace resolves a context that is not this order's — under the scope
     * the parcel reads as absent, and the refund rule below would take a book
     * already on its way for one that never shipped. The row is reached by its
     * own order's key, so there is nothing for the scope to protect.
     *
     * @return HasOne<Shipment, $this>
     */
    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class)->withoutGlobalScope(WorkspaceScope::class);
    }

    /**
     * Whether this is a printed copy that has left the shelf — fulfilled, or its
     * parcel already moving (owner decision 2026-09-25). Such a purchase is not
     * refunded through the site; see {@see RefundStorePurchase}.
     *
     * Asked of the SHIPMENT rather than of the item's kind: every printed
     * purchase gets its `pending` shipment at the moment of sale, and no file
     * ever gets one — so the row is the fact, and reading it spares every list
     * that already eager-loads `shipment` a second relation per row.
     */
    public function printedCopyHasLeftTheShelf(): bool
    {
        $shipment = $this->shipment;

        if ($shipment === null) {
            return false;
        }

        return $this->fulfilled_at !== null || $shipment->status !== ShipmentStatus::Pending;
    }
}
