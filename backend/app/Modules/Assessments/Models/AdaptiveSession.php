<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Assessments\Enums\AdaptiveStatus;
use App\Modules\Assessments\Enums\Difficulty;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Assessments\AdaptiveSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One student practising one concept, the difficulty moving with them.
 *
 * ⚠️ `BelongsToWorkspace` IS ON IT BECAUSE THE CONSTITUTION REQUIRES IT, NOT
 * BECAUSE IT GUARDS THE STUDENT'S PATH. `WorkspaceScope` adds no condition when
 * the context is null, and it is null for every student. The guard is the
 * explicit `student_user_id` condition in each Action, and the uuid is resolved
 * INSIDE the Action rather than by route-model binding — the `RedeemReward`
 * precedent.
 *
 * @property AdaptiveStatus $status
 * @property Difficulty $current_difficulty
 * @property Difficulty $ceiling_difficulty
 * @property int $correct_streak
 * @property int $served_count
 * @property Carbon|null $mastered_at
 * @property Carbon|null $ended_at
 */
class AdaptiveSession extends BaseModel
{
    /** @use HasFactory<AdaptiveSessionFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'student_user_id',
        'concept_id',
        'attempt_id',
        'current_difficulty',
        'ceiling_difficulty',
        'correct_streak',
        'served_count',
        'status',
        'running_key',
        'mastered_at',
        'ended_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => AdaptiveStatus::class,
            'current_difficulty' => Difficulty::class,
            'ceiling_difficulty' => Difficulty::class,
            'correct_streak' => 'integer',
            'served_count' => 'integer',
            'mastered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * The claim key while this session is live.
     *
     * ⚠️ ONE SPELLING, because the value is written by the starter and compared
     * by the database's unique index — two spellings of it means the index guards
     * a string nobody else builds.
     */
    public static function runningKeyFor(int $studentUserId, int $conceptId): string
    {
        return $studentUserId.':'.$conceptId;
    }

    /** @return BelongsTo<Attempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id');
    }

    /** @return BelongsTo<Concept, $this> */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'concept_id');
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /**
     * The attempt behind this session, which the schema guarantees exists.
     *
     * `attempt_id` is NOT NULL and is written in the same call that creates the
     * row, so a miss here is a corrupted database rather than a case to branch
     * on — and the relation caches, so this costs one query per session however
     * many callers ask.
     */
    public function paper(): Attempt
    {
        return $this->attempt ?? throw new RuntimeException("Adaptive session {$this->getKey()} has no attempt.");
    }

    /** The student this session belongs to. `student_user_id` is likewise NOT NULL. */
    public function learner(): User
    {
        return $this->student ?? throw new RuntimeException("Adaptive session {$this->getKey()} has no student.");
    }

    /**
     * The question this session is waiting on, or null when it is between none.
     *
     * ⚠️ DERIVED, NOT STORED. The served items ARE the record of what was shown
     * and `exam_answers` is the record of what was answered, so «the current
     * question» is the one item with no answer row — at most one, always, because
     * the next question is only served after the last one is marked. A
     * `current_question_id` column beside them would be a second answer to a
     * question the two tables already answer, and the two would disagree the
     * first time a write half-failed.
     */
    public function currentItem(): ?AttemptItem
    {
        return AttemptItem::query()
            ->withoutWorkspaceScope()
            ->where('attempt_id', $this->attempt_id)
            ->whereNotExists(fn (QueryBuilder $query) => $query->from('exam_answers')
                ->whereColumn('exam_answers.attempt_id', 'attempt_items.attempt_id')
                ->whereColumn('exam_answers.question_id', 'attempt_items.question_id'))
            ->orderBy('order')
            ->first();
    }

    /**
     * Close this session out, atomically, and release its claim.
     *
     * The check and the write are one statement: two tabs pressing «إنهاء» must
     * not both stamp an end, and — more to the point — a session that reached
     * mastery must not then be overwritten as merely ended. A false return means
     * somebody else got there first, which is not an error.
     */
    public function claimClosure(AdaptiveStatus $status): bool
    {
        $stamp = $status === AdaptiveStatus::Mastered ? 'mastered_at' : 'ended_at';

        return static::query()
            ->whereKey($this->getKey())
            ->where('status', AdaptiveStatus::Running->value)
            ->update([
                'status' => $status->value,
                // Released in the SAME statement that ends the session: a claim
                // dropped a line later is a window in which a second start wins.
                'running_key' => null,
                $stamp => now(),
            ]) === 1;
    }
}
