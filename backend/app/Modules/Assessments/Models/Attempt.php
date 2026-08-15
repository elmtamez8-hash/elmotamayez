<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $status
 * @property int $random_seed
 * @property-read Exam|null $exam null for a self-generated practice run, which
 *   belongs to no exam anybody authored (research: exam_id became nullable)
 */
class Attempt extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    public const STATUS_IN_PROGRESS = 'in_progress';

    /** Held for the duration of grading, so a second submit finds nothing to claim. */
    public const STATUS_GRADING = 'grading';

    /** Auto-marked, but an essay is still waiting on a person (research.md §د). */
    public const STATUS_PENDING_GRADING = 'pending_grading';

    public const STATUS_GRADED = 'graded';

    protected $table = 'exam_attempts';

    protected $fillable = [
        'workspace_id',
        'exam_id',
        'enrollment_id',
        'student_user_id',
        'status',
        'is_practice',
        'finalized_at',
        'score',
        'max_score',
        'passed',
        'random_seed',
        'started_at',
        'submitted_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'passed' => 'boolean',
            'random_seed' => 'integer',
            'is_practice' => 'boolean',
            'finalized_at' => 'datetime',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return HasMany<Answer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'attempt_id');
    }

    /** @return HasMany<AttemptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AttemptItem::class, 'attempt_id')->orderBy('order')->orderBy('id');
    }

    public function isGraded(): bool
    {
        return $this->status === 'graded';
    }

    /**
     * Waiting on a human to read an essay.
     *
     * A status rather than a flag beside `graded`, because a flag makes the
     * condition optional for every reader — and the reader that must never treat
     * it as optional is the one that issues certificates.
     */
    public function isPendingGrading(): bool
    {
        return $this->status === self::STATUS_PENDING_GRADING;
    }

    /**
     * Claim this attempt for grading, atomically.
     *
     * ⚠️ THE CHECK AND THE WRITE ARE ONE STATEMENT, and that is the whole point.
     * The controller used to read `isGraded()` and then write in a separate call;
     * two taps on a flaky connection both pass the read and both write the full
     * answer set. The false return is the refusal — the same shape as seat
     * claiming, order capture and `StructureVersion::claim()`.
     *
     * `lockForUpdate()` is deliberately not used: it is a no-op on SQLite, so a
     * test built around it passes locally and proves nothing about MySQL.
     */
    public function claimForGrading(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_IN_PROGRESS)
            ->update(['status' => self::STATUS_GRADING]) === 1;
    }
}
