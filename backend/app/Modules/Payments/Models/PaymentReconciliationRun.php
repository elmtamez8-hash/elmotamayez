<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;

/**
 * One pass of the payment reconciliation, and what it found.
 *
 * Platform-owned (constitution §I, kind ب): the sweep spans every workspace, so
 * there is no `workspace_id` and no global scope — the guard is the platform
 * permission on the one route that reads it.
 *
 * @property CarbonInterface $ran_at
 * @property CarbonInterface $window_from
 * @property CarbonInterface $window_to
 * @property int $checked_count
 * @property int $corrected_count
 * @property int $unresolved_count
 * @property list<array<string, mixed>>|null $findings
 */
class PaymentReconciliationRun extends BaseModel
{
    use HasUuid;

    protected $fillable = [
        'ran_at',
        'window_from',
        'window_to',
        'checked_count',
        'corrected_count',
        'unresolved_count',
        'findings',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'window_from' => 'datetime',
            'window_to' => 'datetime',
            'checked_count' => 'integer',
            'corrected_count' => 'integer',
            'unresolved_count' => 'integer',
            'findings' => 'array',
        ];
    }
}
