<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Assessments\StudyRoomParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One person inside one room, and how far they have got.
 *
 * ⚠️ ITS `uuid` IS WHAT THE LIVE BOARD CARRIES, and the user's uuid never is —
 * the second is a platform-wide identifier for a person who may be a child,
 * handed to their peers; this one dies with the room.
 *
 * @property Carbon $joined_at
 * @property Carbon|null $finished_at
 * @property int $score
 * @property int $answered_count
 */
class StudyRoomParticipant extends BaseModel
{
    /** @use HasFactory<StudyRoomParticipantFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'study_room_id',
        'user_id',
        'attempt_id',
        'score',
        'answered_count',
        'joined_at',
        'finished_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'finished_at' => 'datetime',
            'score' => 'integer',
            'answered_count' => 'integer',
        ];
    }

    /** @return BelongsTo<StudyRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(StudyRoom::class, 'study_room_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Attempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id');
    }

    /**
     * The practice attempt behind this participation, which the schema
     * guarantees exists.
     *
     * `attempt_id` is NOT NULL and is written in the same transaction that
     * creates the row, so a miss here is a corrupted database rather than a case
     * to branch on. The relation caches, so this costs one query however many
     * callers ask. The `AdaptiveSession::paper()` precedent.
     */
    public function paper(): Attempt
    {
        return $this->attempt ?? throw new RuntimeException("Study room participant {$this->getKey()} has no attempt.");
    }
}
