<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Analytics\Support\ReportCadence;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Analytics\ReportSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing request for the numbers (spec 011 · FR-045).
 *
 * Platform-owned: no `BelongsToWorkspace`. The report is the PLATFORM's figures,
 * read by a platform permission, so a workspace column would be a scope on a row
 * that belongs to no workspace.
 *
 * @property int $user_id
 * @property list<string> $metric_keys
 * @property ReportCadence $cadence
 * @property CarbonImmutable|null $last_sent_on
 * @property bool $is_active
 */
class ReportSubscription extends BaseModel
{
    /** @use HasFactory<ReportSubscriptionFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'user_id',
        'metric_keys',
        'cadence',
        'last_sent_on',
        'is_active',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'metric_keys' => 'array',
            'cadence' => ReportCadence::class,
            'last_sent_on' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }
}
