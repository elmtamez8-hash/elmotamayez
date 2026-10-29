<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $status
 * @property int $random_seed
 * @property CarbonInterface|null $started_at cast `datetime`
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
        // The n-th official sitting; the unique index on it IS the attempt
        // allowance's claim (see `StartAttempt`). NULL on a practice run.
        'attempt_number',
        'duration_minutes',
        'clock_enforced',
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
            'attempt_number' => 'integer',
            'duration_minutes' => 'integer',
            'clock_enforced' => 'boolean',
            // Written by `SaveAttemptDraft` alone, never mass-assigned.
            'draft_answers' => 'array',
            'finalized_at' => 'datetime',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            // Claimed by a conditional UPDATE in `WarnOnConsecutiveFailures`,
            // never mass-assigned.
            'academic_warning_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class)->withoutGlobalScope(WorkspaceScope::class);
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class)->withoutGlobalScope(WorkspaceScope::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return HasMany<Answer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'attempt_id')->withoutGlobalScope(WorkspaceScope::class);
    }

    /** @return HasMany<AttemptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AttemptItem::class, 'attempt_id')->withoutGlobalScope(WorkspaceScope::class)->orderBy('order')->orderBy('id');
    }

    public function isGraded(): bool
    {
        return $this->status === 'graded';
    }

    /**
     * How long after the clock a submission is still taken: a slow connection
     * pressing «سلّم» in the last second is not a cheat.
     */
    public const GRACE_SECONDS = 60;

    /**
     * When this official sitting's time runs out — `started_at` plus the
     * duration frozen at START (accommodation included, so extra time is never
     * re-derived here). Null when the clock does not apply: a practice run, or
     * a sitting with no duration.
     *
     * ⛔ THE SERVER OWNS THIS NOW (owner decision 2026-10-29). The timer used to
     * be the browser's alone — a one-minute exam took an answer seventy seconds
     * in, and an open attempt resumed for ever (QA run, 2026-10-29).
     */
    public function deadline(): ?CarbonInterface
    {
        // A sitting begun before the server owned the clock keeps the old
        // behaviour (see the migration that added `clock_enforced`).
        if (! $this->clock_enforced || $this->is_practice || $this->started_at === null || (int) $this->duration_minutes <= 0) {
            return null;
        }

        return $this->started_at->copy()->addMinutes((int) $this->duration_minutes);
    }

    /** Past the deadline AND its grace — nothing more is taken from the student. */
    public function isClosedByClock(): bool
    {
        $deadline = $this->deadline();

        return $deadline !== null && now()->greaterThan($deadline->copy()->addSeconds(self::GRACE_SECONDS));
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
        return static::query()->withoutWorkspaceScope()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_IN_PROGRESS)
            ->update(['status' => self::STATUS_GRADING]) === 1;
    }

    /**
     * Claim the right to close this attempt out, atomically.
     *
     * ⚠️ TWO GRADERS FINISHING THE LAST TWO ESSAYS BOTH SEE ZERO LEFT. Each
     * counts the ungraded answers, each finds none, and each finalizes: two
     * `AttemptFinalized` events and two "your result is out" messages to one
     * student. `ExamPassed` is absorbed — its item-completion listener is
     * idempotent — but a notification has no such defence, and the student reads the same
     * result twice with two different graders' names behind it.
     *
     * The false return is the refusal, and it is not an error: it means the
     * other grader owns the close.
     */
    public function claimForFinalize(): bool
    {
        return static::query()->withoutWorkspaceScope()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_PENDING_GRADING)
            ->update(['status' => self::STATUS_GRADED]) === 1;
    }
}
