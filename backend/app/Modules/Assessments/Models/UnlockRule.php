<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;

/**
 * What a student must have done to open the session after the last one.
 *
 * ⚠️ `course_id = 0` IS THE WORKSPACE DEFAULT, not a missing value. A nullable
 * column would have made `unique(workspace_id, course_id)` inert on exactly the
 * row every workspace has — NULL never equals NULL — so one teacher saving their
 * default twice would end up with two rules and no way to say which applies.
 *
 * ⚠️ AND THE SPECIFIC ROW REPLACES THE DEFAULT, IT DOES NOT MERGE WITH IT
 * (FR-037). A course rule that turns attendance off must turn it off, even when
 * the workspace default has it on — merging would make "the course does not
 * require attendance" impossible to express.
 *
 * @property bool $requires_attendance
 * @property bool $requires_assignment
 * @property string $min_score_pct the `decimal:2` cast, so a string
 */
class UnlockRule extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    /** The `course_id` of a workspace's default rule. */
    public const DEFAULT_SCOPE = 0;

    protected $fillable = [
        'workspace_id',
        'course_id',
        'requires_attendance',
        'requires_assignment',
        'min_score_pct',
        'is_active',
        'updated_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'requires_attendance' => 'boolean',
            'requires_assignment' => 'boolean',
            'min_score_pct' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function isDefault(): bool
    {
        return (int) $this->course_id === self::DEFAULT_SCOPE;
    }

    /** Does this rule condition anything at all? */
    public function conditions(): bool
    {
        return $this->is_active && ($this->requires_attendance || $this->requires_assignment);
    }
}
