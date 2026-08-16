<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the mark scheme for one essay question.
 *
 * ⚠️ `max_points` IS A STRING WHEN READ. Laravel's `decimal:2` cast returns a
 * string, which is why every sum of these goes through an explicit `(float)` —
 * `array_sum` over the raw values silently concatenates nothing and adds
 * correctly, but a `>` comparison against a string does not always.
 *
 * @property string $label
 * @property string $max_points the `decimal:2` cast, so a string
 * @property int $order
 */
class RubricCriterion extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    protected $fillable = [
        'workspace_id',
        'question_id',
        'label',
        'max_points',
        'order',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'max_points' => 'decimal:2',
            'order' => 'integer',
        ];
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
