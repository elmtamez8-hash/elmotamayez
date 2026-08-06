<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\SettlementBasis;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Settlement\TeachingUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One frozen seat in one delivered session, priced at the rate then in force.
 *
 * **A bridge entity** (constitution I): it carries `workspace_id` for context and
 * points at the platform-owned student, exactly as `Attendance` does. It needs
 * the student because the statement counts students, a correction has to find
 * the seat it corrects, and a dispute is about one person's session.
 *
 * What it deliberately does NOT carry is any financial fact about that student —
 * no payment, no receipt, no balance, not even in a free-form column (FR-003).
 * The teacher's earning does not depend on whether the student paid, and a
 * refund on the other side never touches this row (FR-004): the platform is the
 * seller, so the platform carries the risk of the sale.
 *
 * The unit is the seat, not the attendee. Ten frozen seats with six people
 * present is ten units — the six who came and the four who did not all receive
 * the recording, the files and the homework, so the teacher delivered the
 * package to all ten (Q2ب).
 *
 * @property TeachingUnitStatus $status
 * @property SettlementBasis $basis
 * @property ClassSessionType $session_type
 * @property int $amount_minor
 * @property int $frozen_seats
 * @property bool $recording_fault
 * @property bool $needs_review
 * @property CarbonInterface $delivered_at
 * @property CarbonInterface|null $accrued_at
 * @property CarbonInterface|null $settled_at
 */
class TeachingUnit extends BaseModel
{
    /** @use HasFactory<TeachingUnitFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    /** The `reversal_of_id` value meaning "this is an original". See the migration. */
    public const NOT_A_REVERSAL = 0;

    protected $fillable = [
        'workspace_id',
        'student_user_id',
        'teacher_profile_id',
        'class_session_id',
        'session_type',
        'settlement_rate_id',
        'amount_minor',
        'currency',
        'frozen_seats',
        'basis',
        'status',
        'pending_reason',
        'recording_fault',
        'needs_review',
        'delivered_at',
        'accrued_at',
        'settled_at',
        'settlement_period_id',
        'reversal_of_id',
        'reversal_reason',
        'reversed_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => TeachingUnitStatus::class,
            'basis' => SettlementBasis::class,
            'session_type' => ClassSessionType::class,
            'amount_minor' => 'integer',
            'frozen_seats' => 'integer',
            'recording_fault' => 'boolean',
            'needs_review' => 'boolean',
            'delivered_at' => 'datetime',
            'accrued_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TeacherProfile, $this> */
    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /**
     * The student whose seat this was.
     *
     * Without the workspace scope: the student is platform-owned, and the reader
     * here is always the teacher whose workspace already scopes the unit itself.
     *
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<SettlementRate, $this> */
    public function settlementRate(): BelongsTo
    {
        return $this->belongsTo(SettlementRate::class);
    }

    /** @return BelongsTo<SettlementPeriod, $this> */
    public function settlementPeriod(): BelongsTo
    {
        return $this->belongsTo(SettlementPeriod::class);
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== self::NOT_A_REVERSAL;
    }
}
