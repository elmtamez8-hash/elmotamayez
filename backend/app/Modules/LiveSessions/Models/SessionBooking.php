<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\LiveSessions\SessionBookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's seat in a session.
 *
 * A bridge row (Constitution I): it carries `workspace_id` for context and
 * points at the one platform-wide student. The student is not partitioned by
 * teacher — they book with as many as they like.
 *
 * @property BookingStatus $status
 * @property CarbonInterface $booked_at
 * @property CarbonInterface|null $cancelled_at
 */
class SessionBooking extends BaseModel
{
    /** @use HasFactory<SessionBookingFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'class_session_id',
        'student_user_id',
        'status',
        'is_billable',
        'booked_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'is_billable' => 'boolean',
            'booked_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ClassSession, $this> */
    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }
}
