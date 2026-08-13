<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The price snapshot for one credit purchase.
 *
 * Written once and never recomputed, whatever happens to the teacher's rate
 * afterwards (FR-020, FR-021ز, SC-015ج).
 *
 * All four components are stored, not just the total (FR-021ح). Spec 015's books
 * are generated from these retroactively, and what was not captured at the
 * moment cannot be recovered later — which is why Q-2 made the snapshot
 * mandatory from day one, before the accounting engine exists to read it.
 *
 * bigInteger in the minor unit, matching 014: 2.1 billion minor units is only 21
 * million riyals.
 *
 * @property-read Workspace $workspace workspace_id is NOT NULL
 * @property int $total_minor
 */
class CreditPurchase extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    protected $fillable = [
        'credit_balance_id',
        'credit_package_id',
        'course_id',
        'workspace_id',
        'order_id',
        'credits',
        'teacher_rate_minor',
        'operating_fee_minor',
        'gateway_fee_minor',
        'total_minor',
        'currency',
        'purchased_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'teacher_rate_minor' => 'integer',
            'operating_fee_minor' => 'integer',
            'gateway_fee_minor' => 'integer',
            'total_minor' => 'integer',
            'purchased_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CreditBalance, $this> */
    public function balance(): BelongsTo
    {
        return $this->belongsTo(CreditBalance::class, 'credit_balance_id');
    }

    /** @return BelongsTo<CreditPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(CreditPackage::class, 'credit_package_id');
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /*
    | ⚠️ THERE IS NO `creditTransaction()` RELATION, AND THE ABSENCE IS THE
    | DESIGN. The link is `source_type` + `source_id`, and the only index over
    | those columns is `credit_tx_idempotency` — `(credit_balance_id, type,
    | source_type, source_id)`. A relation cannot carry the leading
    | `credit_balance_id`: an eager load compiles to one statement over many
    | parents, so a `whereColumn` against `credit_purchases.id` refers to a table
    | that is not in scope, and a relation without the balance uses no index at
    | all — every chain read becoming a full scan of the fastest-growing table in
    | the product.
    |
    | So the one reader that needs it — `PaymentAuditController::show()` — asks
    | for all four indexed columns directly. One place, one statement, one index.
    */

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
