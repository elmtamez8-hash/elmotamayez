<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Assessments\Enums\StudyRoomState;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Assessments\StudyRoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A group study room: one frozen paper, several students, a few minutes.
 *
 * ⚠️ `BelongsToWorkspace` IS HERE BECAUSE THE CONSTITUTION REQUIRES IT, NOT
 * BECAUSE IT GUARDS THE STUDENT'S PATH. `WorkspaceScope` adds no condition when
 * the context is null, and it is null for every student. The guard is
 * `StudyRoomAccess`, and every uuid is resolved INSIDE an Action rather than by
 * route-model binding — the `RedeemReward` precedent.
 *
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $question_count
 * @property int $max_participants
 * @property int $duration_minutes
 */
class StudyRoom extends BaseModel
{
    /** @use HasFactory<StudyRoomFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'host_user_id',
        'concept_id',
        'difficulty',
        'question_count',
        'max_participants',
        'duration_minutes',
        'starts_at',
        'ends_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'question_count' => 'integer',
            'max_participants' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    /**
     * Where this room is, right now.
     *
     * ⚠️ READ FROM THE CLOCK, AND FROM NOTHING ELSE. That is the whole reason
     * there is no closing job: a room whose hour has passed is closed even if
     * every worker on the platform is stopped. `data-model.md` also lists a
     * `closed_at` column, and it is deliberately NOT here — no requirement asks
     * for an early close, so it would have had this one reader and no writer at
     * all. The migration records the departure and its reason.
     */
    public function state(): StudyRoomState
    {
        $now = now();

        if ($now->greaterThanOrEqualTo($this->ends_at)) {
            return StudyRoomState::Closed;
        }

        return $now->greaterThanOrEqualTo($this->starts_at)
            ? StudyRoomState::Live
            : StudyRoomState::Pending;
    }

    /** @return HasMany<StudyRoomQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(StudyRoomQuestion::class);
    }

    /** @return HasMany<StudyRoomParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(StudyRoomParticipant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /** @return BelongsTo<Concept, $this> */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'concept_id');
    }
}
