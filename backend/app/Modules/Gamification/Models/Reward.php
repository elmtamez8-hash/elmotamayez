<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Modules\Gamification\Enums\RewardType;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Gamification\RewardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shop item — WORKSPACE-owned (layer 2). What the teacher produces and pays for.
 *
 * ⚠️ `month_key` AND `month_redeemed` ARE NOT DERIVED FROM `redemptions`, and
 * that is the point: claiming stock and claiming a slot under the monthly cap
 * happen in ONE conditional statement. Counting redemption rows inside the write
 * path would be the read-then-write race again.
 *
 * @property RewardType $type
 * @property int $price_coins
 * @property int $stock
 * @property int|null $monthly_cap
 * @property int $month_redeemed
 * @property bool $is_active
 */
class Reward extends BaseModel
{
    /** @use HasFactory<RewardFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'title',
        'price_coins',
        'stock',
        'type',
        'monthly_cap',
        'is_active',
    ];

    /*
    | ⚠️ `month_key` AND `month_redeemed` ARE DELIBERATELY NOT FILLABLE. They are
    | the lock, written only inside the atomic claim; mass-assignable, they become
    | a second way to release a slot from outside the statement that owns it —
    | the same reason Payments keeps `captured_order_id` out of its fillable list.
    */

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => RewardType::class,
            'price_coins' => 'integer',
            'stock' => 'integer',
            'monthly_cap' => 'integer',
            'month_redeemed' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<Redemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }
}
