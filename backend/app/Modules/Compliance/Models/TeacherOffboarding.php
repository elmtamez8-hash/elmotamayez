<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Compliance\Enums\OffboardingStatus;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher winding down their workspace — BRIDGE.
 *
 * ⚠️ THE ONE MODEL IN THIS MODULE THAT USES `BelongsToWorkspace`, and the
 * declaration is required rather than inferred: the thing being wound down IS a
 * workspace, so the request is about one. It joins the two shipped bridges
 * (`Enrollment`, `SessionBooking`), and the constitution requires a case in
 * `WorkspaceIsolationTest` in the same commit — an earlier draft of the plan said
 * "no workspace-owned model is added" precisely because nobody had declared this
 * one.
 *
 * @property int $workspace_id
 * @property int $teacher_user_id
 * @property OffboardingStatus $status
 * @property CarbonImmutable|null $settlement_cleared_at
 * @property CarbonImmutable|null $students_notified_at
 * @property CarbonImmutable|null $notice_ends_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $completed_by_user_id
 * @property string|null $content_export_path
 */
class TeacherOffboarding extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    /**
     * ⚠️ `status`, `settlement_cleared_at` AND `completed_at` ARE ABSENT. Each is
     * written by a conditional UPDATE — completion is refused while settlement is
     * null, and two operators pressing complete must not both pass — so a fillable
     * path around them is a path around the check.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'teacher_user_id',
        'notice_ends_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => OffboardingStatus::class,
            'settlement_cleared_at' => 'immutable_datetime',
            'students_notified_at' => 'immutable_datetime',
            'notice_ends_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_user_id');
    }

    /**
     * Whether nothing is outstanding in either direction.
     *
     * ⚠️ A METHOD ON THE MODEL BECAUSE THE RESOURCE MAY NOT READ THE COLUMN.
     * `ContextIsolationTest` forbids settlement vocabulary in any payload outside
     * that module — comments stripped, so the property access itself is what
     * fires. The rule is right and the fix is not a rename: a screen that talks
     * about settlement is one field away from carrying a number from it, and the
     * boundary belongs at the edge of the payload rather than in the schema.
     */
    public function duesCleared(): bool
    {
        return $this->settlement_cleared_at !== null;
    }
}
