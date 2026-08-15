<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Assessments\Support\MistakeNotebook;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's answer to one question in one attempt.
 *
 * ⚠️ `student_user_id` IS DUPLICATED FROM THE PARENT ATTEMPT ON PURPOSE
 * (plan.md §Complexity). The mistake notebook asks "every wrong answer by this
 * student" on the longest page a student reads, and NFR-010 forbids the cost
 * growing with how many attempts they have made. It is written once, at insert,
 * and no code path ever updates it — which is why the duplication cannot drift.
 *
 * ⚠️ `uuid` IS NEW IN SPEC 008 AND NOT DECORATION. The grading routes bind to an
 * answer, and the table shipped with an autoincrement id only; the alternative
 * was exposing a sequential id, which no route in this product does.
 *
 * ⚠️ `is_resolved` and `times_wrong` ARE NOT COLUMNS. {@see MistakeNotebook}
 * derives them in its grouped query and sets them on the model it hands back —
 * a stored "fixed" flag drifts at the first manual regrade and needs a sweep to
 * repair it, and the sweep needs a sweep watching it.
 *
 * @property bool $is_correct
 * @property int $points
 * @property int $grading_version
 * @property array<int, int>|null $selected_option_ids the `array` cast, not the raw json column
 * @property bool $is_resolved derived by MistakeNotebook, never stored
 * @property int $times_wrong derived by MistakeNotebook, never stored
 */
class Answer extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    protected $table = 'exam_answers';

    protected $fillable = [
        'workspace_id',
        'attempt_id',
        'question_id',
        'student_user_id',
        'selected_option_ids',
        'answer_text',
        'is_correct',
        'points',
        'requires_grading',
        'graded_at',
        'graded_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'selected_option_ids' => 'array',
            'is_correct' => 'boolean',
            'points' => 'integer',
            'requires_grading' => 'boolean',
            'graded_at' => 'datetime',
            'grading_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Attempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id');
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    /**
     * Claim this answer for its FIRST grading, atomically.
     *
     * ⚠️ THE CLAIM LIVES HERE AND NOT ON `grading_records`, and the difference is
     * the whole of FR-035. That table is append-only and its `graded_at` is never
     * null, so `WHERE graded_at IS NULL` over it matches nothing — there is no row
     * to update before the insert. Worse, the unique index once proposed as the
     * guard, `(answer_id, rubric_criterion_id, revision_of)`, has two nullable
     * columns: an essay with no rubric writes NULL into both, and NULL does not
     * collide with NULL. Two graders would both succeed, silently, in exactly the
     * case the guard existed for.
     *
     * A false return is the conflict. `lockForUpdate()` is refused: no-op on
     * SQLite, so a test built on it passes locally and proves nothing.
     */
    public function claimForGrading(int $graderId): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->whereNull('graded_at')
            ->update([
                'graded_at' => now(),
                'graded_by' => $graderId,
            ]) === 1;
    }

    /**
     * Claim this answer for a REVISION of a grade already recorded.
     *
     * The version read is the version written against, in one statement — the
     * same shape as `StructureVersion::claim()`. Comparing a loaded model and
     * incrementing afterwards is the lost update it exists to prevent.
     */
    public function claimForRevision(int $expectedVersion, int $graderId): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('grading_version', $expectedVersion)
            ->update([
                'grading_version' => $expectedVersion + 1,
                'graded_at' => now(),
                'graded_by' => $graderId,
            ]) === 1;
    }
}
