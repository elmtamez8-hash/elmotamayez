<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One entry in the append-only ledger (FR-002, FR-005).
 *
 * The guard is on the MODEL, not only in the Action, so the route taken to reach
 * it does not matter — tinker, a seeder and Filament are all covered by the same
 * three lines. Copied from Settlement\Models\LedgerEntry, which does exactly
 * this and is already tested.
 *
 * The known hole stays known: a mass update() loads no models and so bypasses
 * booted(). No later write is sanctioned anywhere in this phase, which is what
 * keeps that from mattering.
 *
 * uuid and created_at are written EXPLICITLY by the Action, in the insert array.
 * They cannot be left to the framework here: the ledger is written with
 * insertOrIgnore, a Query Builder call, so no `creating` event fires and
 * HasUuid's boot hook never runs. On MySQL the NOT NULL violation is then
 * downgraded to a warning and '' is stored — after which every subsequent entry
 * on the platform collides with that row on unique(uuid), is read as "already
 * recorded", and is skipped. The ledger stops after one row while every call
 * reports success.
 *
 * @property CreditTransactionType $type
 * @property int $credits
 * @property Carbon|null $created_at restated because Larastan reads it as a raw
 *                                   `timestamp` from the migration and does not see casts()
 */
class CreditTransaction extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'credit_balance_id',
        'workspace_id',
        'type',
        'credits',
        'source_type',
        'source_id',
        'performed_by',
        'reason',
        'meta',
        'created_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'credits' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('سجلّ الأرصدة مضاف لا يُعدَّل — التصحيح بقيد جديد.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('سجلّ الأرصدة مضاف لا يُحذف — التصحيح بقيد جديد.');
        });
    }

    /** @return BelongsTo<CreditBalance, $this> */
    public function balance(): BelongsTo
    {
        return $this->belongsTo(CreditBalance::class, 'credit_balance_id');
    }

    /**
     * Which lots paid for this consumption (FR-028's last link).
     *
     * The draw's own record, and it cannot be derived afterwards:
     * soonest-expiring-first rewrites the answer retroactively every time a
     * sooner-expiring lot arrives, so what a past consumption CLAIMED is only
     * knowable from the row written at the time.
     *
     * @return HasMany<CreditAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(CreditAllocation::class, 'consumed_transaction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
