<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\RateRequestStatus;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Settlement\RateChangeRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The teacher asked; the platform decides.
 *
 * Approving this row is the ONLY thing in the system that creates a
 * SettlementRate. That is not a convention to remember — it is what makes "no
 * rate takes effect without approval" (SC-005أ) structural rather than a rule a
 * reviewer has to keep noticing.
 *
 * @property RateRequestStatus $status
 * @property ClassSessionType $session_type
 * @property int $requested_amount_minor
 * @property int|null $current_amount_minor
 * @property CarbonInterface $requested_at
 * @property CarbonInterface|null $decided_at
 */
class RateChangeRequest extends BaseModel
{
    /** @use HasFactory<RateChangeRequestFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'teacher_profile_id',
        'session_type',
        'subject_id',
        'grade_level',
        'current_amount_minor',
        'requested_amount_minor',
        'currency',
        'status',
        'requested_by',
        'requested_at',
        'decided_by',
        'decided_at',
        'decision_reason',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => RateRequestStatus::class,
            'session_type' => ClassSessionType::class,
            'current_amount_minor' => 'integer',
            'requested_amount_minor' => 'integer',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TeacherProfile, $this> */
    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
