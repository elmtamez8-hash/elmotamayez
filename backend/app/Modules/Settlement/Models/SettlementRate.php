<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Settlement\SettlementRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the platform pays this teacher for one unit, from a given date.
 *
 * Versioned by insertion: a row is never updated, and a correction is a newer
 * row (FR-010 · FR-011). An UPDATE here would reprice work already done, which
 * is the exact dispute this whole context exists to prevent — and it would take
 * one typo.
 *
 * Workspace-owned: it says nothing about any student.
 *
 * @property ClassSessionType $session_type
 * @property int $amount_minor
 * @property CarbonInterface $effective_from
 */
class SettlementRate extends BaseModel
{
    /** @use HasFactory<SettlementRateFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'teacher_profile_id',
        'session_type',
        'subject_id',
        'grade_level',
        'amount_minor',
        'currency',
        'effective_from',
        'approved_by',
        'rate_change_request_id',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'session_type' => ClassSessionType::class,
            'amount_minor' => 'integer',
            'effective_from' => 'datetime',
        ];
    }

    /** @return BelongsTo<TeacherProfile, $this> */
    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * How narrow this rate is, for the "most specific wins" ordering (FR-014ب).
     *
     * A number rather than a comparison chain at the call site: the ordering has
     * to be declared and constant, and three call sites deciding it separately
     * is three chances to declare it differently.
     */
    public function specificity(): int
    {
        return ($this->subject_id === null ? 0 : 2) + ($this->grade_level === null ? 0 : 1);
    }
}
