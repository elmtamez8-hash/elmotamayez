<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A batch of credits with its own remaining counter — the one mutable table
 * beside an append-only ledger, and mutable for a reason.
 *
 * Lot selection used to derive "how much is left in this lot" with
 * SUM(credit_allocations): a read followed by a write, in the single place the
 * phase's own concurrency rule was not applied. Two concurrent consumers both
 * read "1 left" and both insert an allocation, and SC-001 still passes — it
 * never looks at allocations. A lot is a package of 8 or 16, so this is live
 * from day one, with expiry switched off.
 *
 * The counter cannot live on CreditTransaction: that model throws on update.
 * Hence a table.
 *
 * Drawn with one conditional UPDATE per lot, ordered undated-last then
 * soonest-expiring. Zero rows affected means someone else emptied it — move to
 * the next lot, never re-read and retry.
 *
 * @property int $credits_total
 * @property int $credits_remaining
 */
class CreditLot extends BaseModel
{
    use BelongsToWorkspace;

    protected $fillable = [
        'credit_transaction_id',
        'credit_balance_id',
        'workspace_id',
        'credits_total',
        'credits_remaining',
        'expires_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'credits_total' => 'integer',
            'credits_remaining' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CreditTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CreditTransaction::class, 'credit_transaction_id');
    }

    /** @return BelongsTo<CreditBalance, $this> */
    public function balance(): BelongsTo
    {
        return $this->belongsTo(CreditBalance::class, 'credit_balance_id');
    }
}
