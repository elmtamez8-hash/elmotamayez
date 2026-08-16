<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * What one grader awarded, on one criterion, at one moment.
 *
 * ⚠️ APPEND-ONLY, ENFORCED ON THE MODEL. FR-032 asks that a changed grade keep
 * who changed it, when and why; an UPDATE erases precisely the row being
 * audited. A correction is a NEW record carrying `revision_of`, on the same
 * precedent as `LedgerEntry` in spec 006 — and for the same reason the guard
 * sits here rather than only inside the Action: an Action is one door, and a
 * model is every door.
 *
 * @property string $points the `decimal:2` cast, so a string
 * @property int $grading_version
 */
class GradingRecord extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'answer_id',
        'rubric_criterion_id',
        'points',
        'comment',
        'graded_by',
        'revision_of',
        'revision_reason',
        'grading_version',
        'created_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'points' => 'decimal:2',
            'grading_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('Grading records are append-only; record a revision instead.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('Grading records are append-only; record a revision instead.');
        });
    }

    /** @return BelongsTo<Answer, $this> */
    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class, 'answer_id');
    }

    /** @return BelongsTo<RubricCriterion, $this> */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class, 'rubric_criterion_id');
    }

    /** @return BelongsTo<User, $this> */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
