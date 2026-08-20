<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Models\BaseModel;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonImmutable;

/**
 * The log of one nightly sweep (FR-031) — PLATFORM reference data (layer ب).
 *
 * ⚠️ `findings_count` IS NOT DERIVABLE FROM `findings`, deliberately. The json is
 * capped so one bad night cannot fill the disk, and the count stays honest
 * whatever was truncated out of it — which is what lets a run that FOUND NOTHING
 * be told apart from a run that DID NOT LOOK. The same separation, for the same
 * stated reason, as `credit_reconciliation_runs`.
 *
 * @property CarbonImmutable $ran_at
 * @property int $categories_processed
 * @property int $rows_deleted
 * @property int $rows_anonymised
 * @property int $rows_archived
 * @property array<int, mixed>|null $findings
 * @property int $findings_count
 */
class RetentionSweepRun extends BaseModel
{
    use HasUuid;

    /** How many findings are kept verbatim before the list is truncated. */
    public const FINDINGS_CAP = 50;

    protected $fillable = [
        'ran_at',
        'categories_processed',
        'rows_deleted',
        'rows_anonymised',
        'rows_archived',
        'findings',
        'findings_count',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'ran_at' => 'immutable_datetime',
            'categories_processed' => 'integer',
            'rows_deleted' => 'integer',
            'rows_anonymised' => 'integer',
            'rows_archived' => 'integer',
            'findings' => 'array',
            'findings_count' => 'integer',
        ];
    }
}
