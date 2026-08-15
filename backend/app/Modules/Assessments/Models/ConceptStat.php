<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Modules\Courses\Models\Lesson;
use App\Shared\Traits\BelongsToWorkspace;
use Database\Factories\Modules\Assessments\ConceptStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How often a concept is answered wrongly — overall, and per lesson (FR-011).
 *
 * ⚠️ `lesson_id = 0` IS THE "CONCEPT OVERALL" ROW, and it is a zero rather than
 * a NULL so the unique index actually bites on it. See the migration header for
 * what NULL does to the nightly `upsert()`.
 *
 * Same shape as {@see QuestionStat}: no uuid, no timestamps, written only by
 * the rollup job.
 *
 * @property int $lesson_id 0 = the concept across every lesson
 * @property int $attempts_count
 * @property int $wrong_count
 * @property float|null $wrong_pct
 * @property-read Concept|null $concept
 */
class ConceptStat extends BaseModel
{
    /** @use HasFactory<ConceptStatFactory> */
    use BelongsToWorkspace, HasFactory;

    /** What `lesson_id` holds on the row that answers "this concept, everywhere". */
    public const OVERALL = 0;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'concept_id',
        'lesson_id',
        'attempts_count',
        'wrong_count',
        'wrong_pct',
        'computed_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'lesson_id' => 'integer',
            'attempts_count' => 'integer',
            'wrong_count' => 'integer',
            'wrong_pct' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Concept, $this> */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    /**
     * The lesson this row is about, or null on the "concept overall" row.
     *
     * `lesson_id = 0` matches nothing, which is the right answer rather than an
     * accident: the overall row is about no single lesson.
     *
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
