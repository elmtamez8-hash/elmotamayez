<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Assessments\Enums\Difficulty;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Assessments\ConceptMasteryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * This student has mastered this concept, on the criterion recorded here.
 *
 * ⚠️ THE ROW IS THE ANSWER, AND IT IS NOT RECOMPUTED. The threshold is an
 * operator-editable setting, so deriving mastery would withdraw it retroactively
 * from everyone the moment somebody raised the number. `threshold_correct` and
 * `threshold_difficulty` are what let a reader a year later say which test this
 * was granted on.
 *
 * @property Difficulty $threshold_difficulty
 * @property int $threshold_correct
 * @property Carbon|null $mastered_at
 */
class ConceptMastery extends BaseModel
{
    /** @use HasFactory<ConceptMasteryFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'student_user_id',
        'concept_id',
        'mastered_at',
        'threshold_correct',
        'threshold_difficulty',
        'source_session_id',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'mastered_at' => 'datetime',
            'threshold_correct' => 'integer',
            'threshold_difficulty' => Difficulty::class,
        ];
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
}
