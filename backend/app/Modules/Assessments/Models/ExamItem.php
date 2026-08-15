<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Assessments\ExamItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bank question included in an exam, at a position and optionally at a
 * different worth.
 *
 * This is the join that replaced `questions.exam_id`. The question belongs to
 * the bank; the exam merely includes it — which is what lets one question live
 * in three exams as one row.
 *
 * @property int $order
 * @property int|null $points_override
 * @property-read Question $question question_id is NOT NULL, so it always resolves
 */
class ExamItem extends BaseModel
{
    /** @use HasFactory<ExamItemFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'exam_id',
        'question_id',
        'order',
        'points_override',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'points_override' => 'integer',
        ];
    }

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * What this question is worth in THIS exam.
     *
     * Null override means "whatever the bank says", so the teacher who raises a
     * question's default worth raises it everywhere they did not say otherwise.
     */
    public function effectivePoints(): int
    {
        return $this->points_override ?? (int) $this->question->points;
    }
}
