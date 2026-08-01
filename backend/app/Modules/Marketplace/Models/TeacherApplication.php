<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Marketplace\TeacherApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A teacher's four-step application, from draft to a review decision.
 *
 * @property string $status
 * @property int $current_step
 * @property array<string, mixed>|null $step_data
 * @property string|null $rejection_reason
 * @property Carbon|null $submitted_at
 * @property Carbon|null $reviewed_at
 */
class TeacherApplication extends BaseModel
{
    /** @use HasFactory<TeacherApplicationFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const LAST_STEP = 4;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'teacher_profile_id',
        'status',
        'current_step',
        'step_data',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'step_data' => 'array',
            'current_step' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<TeacherProfile, $this> */
    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /**
     * Whether the applicant may still edit their answers.
     *
     * A submitted application is frozen so the reviewer is not deciding on a
     * moving target; a rejected one is final. Only "changes requested" reopens it.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CHANGES_REQUESTED], true);
    }

    /**
     * One step's answers, or an empty array if it has not been filled yet.
     *
     * @return array<string, mixed>
     */
    public function step(int $number): array
    {
        $data = $this->step_data ?? [];
        $step = $data['step_'.$number] ?? [];

        return is_array($step) ? $step : [];
    }

    /** @param array<string, mixed> $answers */
    public function putStep(int $number, array $answers): void
    {
        $this->step_data = [...($this->step_data ?? []), 'step_'.$number => $answers];

        // The wizard resumes at the furthest step reached, never rewinding someone
        // who went back to fix an earlier answer (FR-070).
        $this->current_step = max($this->current_step, min($number + 1, self::LAST_STEP));
    }
}
