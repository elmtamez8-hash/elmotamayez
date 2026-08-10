<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;

/**
 * One pass of the reconciliation, and what it found.
 *
 * Platform-owned: the sweep spans every workspace, so there is no workspace_id
 * and no global scope — the guard is the platform permission on the one route
 * that reads it.
 *
 * @property CarbonInterface $ran_at
 * @property list<array<string, mixed>>|null $findings
 */
class CreditReconciliationRun extends BaseModel
{
    use HasUuid;

    protected $fillable = [
        'ran_at',
        'balances_checked',
        'sessions_checked',
        'findings_count',
        'findings',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'balances_checked' => 'integer',
            'sessions_checked' => 'integer',
            'findings_count' => 'integer',
            'findings' => 'array',
        ];
    }
}
