<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Settlement\TeacherPayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money that left. Recorded by hand in the first release, automated in 012.
 *
 * The unique index on `settlement_period_id` is the real guard against paying
 * twice — a check inside the Action protects the API path and nothing else, and
 * the settlement cycle is exactly the kind of thing that gets re-run.
 *
 * @property int $amount_minor
 * @property CarbonInterface $executed_at
 */
class TeacherPayout extends BaseModel
{
    /** @use HasFactory<TeacherPayoutFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'teacher_profile_id',
        'settlement_period_id',
        'amount_minor',
        'currency',
        'reference',
        'method',
        'executed_at',
        'executed_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'executed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SettlementPeriod, $this> */
    public function settlementPeriod(): BelongsTo
    {
        return $this->belongsTo(SettlementPeriod::class);
    }

    /** @return BelongsTo<TeacherProfile, $this> */
    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
