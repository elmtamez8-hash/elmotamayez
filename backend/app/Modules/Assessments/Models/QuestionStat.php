<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Modules\Assessments\Jobs\RollUpQuestionStatsJob;
use App\Shared\Traits\BelongsToWorkspace;
use Database\Factories\Modules\Assessments\QuestionStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How often one question is answered wrongly (FR-011).
 *
 * ⚠️ NO `HasUuid`, AND NO TIMESTAMPS. The only writer is {@see RollUpQuestionStatsJob}
 * and it writes with `upsert()`, which boots no model — a uuid trait here would
 * never fire and Eloquent's timestamps would be injected into an array that has
 * no columns for them.
 *
 * `wrong_pct` is null below the declared sample floor and is NEVER zero there
 * (FR-013). Readers ask `wrong_pct === null`, not `=== 0`.
 *
 * @property int $attempts_count
 * @property int $wrong_count
 * @property float|null $wrong_pct
 * @property-read Question|null $question
 */
class QuestionStat extends BaseModel
{
    /** @use HasFactory<QuestionStatFactory> */
    use BelongsToWorkspace, HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'question_id',
        'attempts_count',
        'wrong_count',
        'wrong_pct',
        'computed_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'attempts_count' => 'integer',
            'wrong_count' => 'integer',
            // float, not decimal:2 — the decimal cast returns a STRING, and a
            // percentage that arrives at the client quoted is a number every
            // reader has to parse back before it can be sorted.
            'wrong_pct' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
