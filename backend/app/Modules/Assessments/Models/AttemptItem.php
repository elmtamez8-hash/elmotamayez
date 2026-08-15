<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use Database\Factories\Modules\Assessments\AttemptItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question as a student saw it, frozen when their attempt started.
 *
 * Grading reads this, never the live question. That is what makes FR-004 hold:
 * a teacher may rewrite a question or delete an option, and a past attempt still
 * renders and still scores exactly as it did.
 *
 * No uuid: it is never addressed on its own. It is reached through its attempt,
 * which has one.
 *
 * @property array<string, mixed> $snapshot
 * @property int $points
 * @property int $order
 */
class AttemptItem extends BaseModel
{
    /** @use HasFactory<AttemptItemFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'workspace_id',
        'attempt_id',
        'question_id',
        'order',
        'points',
        'snapshot',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'points' => 'integer',
            'order' => 'integer',
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

    /**
     * The option ids that were correct when this paper was handed over.
     *
     * @return list<int>
     */
    public function correctOptionIds(): array
    {
        /** @var list<int> */
        return array_map('intval', $this->snapshot['correct_option_ids'] ?? []);
    }

    /**
     * True when this row was reconstructed by the 008 backfill rather than
     * captured at the time.
     *
     * A reconstruction is built from the question as it stands today, which is
     * not testimony — so the review screen says so rather than presenting it as
     * what the student saw.
     */
    public function isBackfilled(): bool
    {
        return (bool) ($this->snapshot['backfilled'] ?? false);
    }

    /** Essays carry no correct set and are graded by a person. */
    public function requiresGrading(): bool
    {
        return ($this->snapshot['type'] ?? 'mcq') === 'essay';
    }
}
