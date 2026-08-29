<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One number, for one day, for one scope (spec 011 · FR-044).
 *
 * ⚠️ NO `HasUuid` AND NO `BelongsToWorkspace`. The rollup writes with `upsert()`,
 * which never boots a model, so a trait that fills a column on create would fill
 * nothing — and `workspace_id = 0` is a real row here (the platform total) that a
 * tenant scope would hide from the one screen that needs it.
 *
 * @property CarbonImmutable $date
 * @property string $metric_key
 * @property int $workspace_id
 * @property int $region_id
 * @property int $numerator
 * @property int $denominator
 */
class PlatformMetricDaily extends Model
{
    protected $table = 'platform_metrics_daily';

    protected $fillable = [
        'date',
        'metric_key',
        'workspace_id',
        'region_id',
        'numerator',
        'denominator',
        'computed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'numerator' => 'integer',
            'denominator' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * The number a screen prints.
     *
     * ⚠️ A ZERO DENOMINATOR IS «NOT A RATIO», NEVER A DIVISION BY ZERO. Counts
     * are stored with denominator 0 deliberately, so this is the ordinary case
     * and not a defensive branch.
     */
    public function value(): float
    {
        return $this->denominator === 0
            ? (float) $this->numerator
            : $this->numerator / $this->denominator;
    }
}
