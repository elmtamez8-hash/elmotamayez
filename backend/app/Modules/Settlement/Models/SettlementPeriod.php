<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Settlement\SettlementPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A stretch of days, closed once, with its totals frozen at that moment.
 *
 * The totals are stored rather than derived, and that is the point: a statement
 * that recomputes gives a different answer after any later correction, including
 * to a teacher who has already been paid against the old one. Closing is the
 * moment the number stops moving.
 *
 * @property SettlementPeriodStatus $status
 * @property CarbonInterface $starts_on
 * @property CarbonInterface $ends_on
 * @property int $units_count
 * @property int $gross_minor
 * @property int $deductions_minor
 * @property int $net_minor
 * @property int $carried_in_minor
 * @property int $carried_out_minor
 * @property CarbonInterface|null $closed_at
 */
class SettlementPeriod extends BaseModel
{
    /** @use HasFactory<SettlementPeriodFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'teacher_profile_id',
        'starts_on',
        'ends_on',
        'status',
        'units_count',
        'gross_minor',
        'deductions_minor',
        'net_minor',
        'carried_in_minor',
        'carried_out_minor',
        'currency',
        'closed_at',
        'closed_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => SettlementPeriodStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'units_count' => 'integer',
            'gross_minor' => 'integer',
            'deductions_minor' => 'integer',
            'net_minor' => 'integer',
            'carried_in_minor' => 'integer',
            'carried_out_minor' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TeacherProfile, $this> */
    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /** @return HasMany<TeachingUnit, $this> */
    public function teachingUnits(): HasMany
    {
        return $this->hasMany(TeachingUnit::class);
    }

    /** @return HasOne<TeacherPayout, $this> */
    public function payout(): HasOne
    {
        return $this->hasOne(TeacherPayout::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
