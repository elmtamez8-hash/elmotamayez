<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which lot paid which consumption.
 *
 * A record of what the draw CLAIMED, not the source of truth — the lot's own
 * counter is. It exists because the question cannot be derived after the fact:
 * soonest-expiry-first rewrites the answer retroactively every time a
 * sooner-expiring lot arrives, so once the record is lost the ground truth is
 * lost with it.
 *
 * The one credit table with NO workspace_id, deliberately: a join table between
 * two rows that are both already scoped, reachable only through transaction ids
 * the caller has already resolved. No route reads it and no payload carries it.
 */
class CreditAllocation extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'consumed_transaction_id',
        'lot_transaction_id',
        'credits',
        'created_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CreditTransaction, $this> */
    public function consumption(): BelongsTo
    {
        return $this->belongsTo(CreditTransaction::class, 'consumed_transaction_id');
    }

    /** @return BelongsTo<CreditTransaction, $this> */
    public function lotEntry(): BelongsTo
    {
        return $this->belongsTo(CreditTransaction::class, 'lot_transaction_id');
    }
}
