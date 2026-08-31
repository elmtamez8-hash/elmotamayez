<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use Database\Factories\Modules\Assessments\StudyRoomQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question on a room's frozen paper.
 *
 * ⚠️ NO `HasUuid`. The row is addressed by `order` inside its room and by
 * nothing from outside, and this repository's rule is that a uuid exists so a
 * route can carry it. There is no route that carries this one.
 *
 * @property array<string, mixed> $snapshot
 * @property int $order
 * @property int $points
 */
class StudyRoomQuestion extends BaseModel
{
    /** @use HasFactory<StudyRoomQuestionFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'workspace_id',
        'study_room_id',
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
            'order' => 'integer',
            'points' => 'integer',
        ];
    }

    /** @return BelongsTo<StudyRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(StudyRoom::class, 'study_room_id');
    }
}
