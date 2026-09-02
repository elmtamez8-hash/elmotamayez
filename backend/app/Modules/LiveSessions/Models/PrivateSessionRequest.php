<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A student's ask for one private hour, and nothing that has happened yet.
 *
 * @property string $status
 * @property int $duration_minutes
 * @property Carbon $starts_at
 * @property Carbon $expires_at
 * @property Carbon|null $decided_at
 * @property int|null $class_session_id
 */
class PrivateSessionRequest extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    /** The deadline ran out, or the teacher left the platform (FR-023 · FR-026). */
    public const EXPIRED = 'expired';

    /*
    | ⚠️ `status`, `pending_slot` AND `class_session_id` ARE NOT FILLABLE.
    |
    | All three are written inside the one statement that settles the request —
    | the `captured_order_id` idiom. Mass-assignable, each becomes a second way
    | to claim a lock the transaction owns: a PATCH body carrying `status:
    | accepted` would decide a request from outside the Action that creates the
    | session behind it.
    |
    | The row is in fact written by a raw `INSERT … SELECT` (the three-request
    | ceiling is a count and a write in ONE statement), so this list guards the
    | panel and any later writer rather than the Action — which is exactly why
    | that insert names `uuid` and the timestamps explicitly: no model is booted
    | there and `HasUuid` never fires.
    */
    protected $fillable = [
        'workspace_id',
        'course_id',
        'student_user_id',
        'teacher_profile_id',
        'starts_at',
        'duration_minutes',
        'expires_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<ClassSession, $this> */
    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** When it would end, if it were granted. */
    public function endsAt(): Carbon
    {
        return $this->starts_at->copy()->addMinutes($this->duration_minutes);
    }

    /**
     * @param  Builder<PrivateSessionRequest>  $query
     * @return Builder<PrivateSessionRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }
}
