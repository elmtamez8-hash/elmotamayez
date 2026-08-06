<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Models;

use App\Models\BaseModel;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Models\MediaAsset;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\LiveSessions\ClassSessionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One taught session: a time, a length, a number of seats, and a room.
 *
 * Workspace-owned — the teacher produces it. Named ClassSession and not Session:
 * that name belongs to AuthSession (spec 004) and to the framework's own session
 * helper, and `use ...\Models\Session;` is a line reviewers misread every time.
 *
 * @property ClassSessionStatus $status
 * @property ClassSessionType $type
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $seats_frozen_at
 * @property CarbonInterface|null $room_opened_at
 * @property CarbonInterface|null $room_closed_at
 */
class ClassSession extends BaseModel
{
    /** @use HasFactory<ClassSessionFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'teacher_profile_id',
        'course_id',
        'subject_id',
        'title',
        'type',
        'status',
        'starts_at',
        'ends_at',
        'duration_minutes',
        'seats_total',
        'seats_taken',
        'billable_seats',
        'seats_frozen_at',
        'broadcast_provider',
        'broadcast_room_id',
        'room_opened_at',
        'room_closed_at',
        'recording_status',
        'recording_attempts',
        'media_asset_id',
        'delivered_at',
        'interruption_note',
        'cancelled_at',
        'cancellation_reason',
        'created_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => ClassSessionType::class,
            'status' => ClassSessionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'duration_minutes' => 'integer',
            'seats_total' => 'integer',
            'seats_taken' => 'integer',
            'billable_seats' => 'integer',
            'seats_frozen_at' => 'datetime',
            'room_opened_at' => 'datetime',
            'room_closed_at' => 'datetime',
            'recording_attempts' => 'integer',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TeacherProfile, $this> */
    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /** @return HasMany<SessionBooking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(SessionBooking::class);
    }

    /** @return HasMany<Attendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * The lesson this session's recording became, if it was published.
     *
     * A relation rather than a lookup in the Resource, because a Resource runs
     * once per row: a month of sessions was costing a month of single-row
     * SELECTs against `lessons`. The workspace scope is dropped here because the
     * student's timetable crosses workspaces by design — their own reader
     * context is one teacher's workspace while the session belongs to another's.
     *
     * @return HasOne<Lesson, $this>
     */
    public function recordingLesson(): HasOne
    {
        return $this->hasOne(Lesson::class, 'class_session_id')->withoutWorkspaceScope();
    }

    public function seatsAvailable(): int
    {
        return max(0, $this->seats_total - $this->seats_taken);
    }

    /** Arrive by this moment and lateness is forgiven (FR-021). */
    public function graceEndsAt(): CarbonInterface
    {
        return $this->starts_at->copy()->addMinutes(app(SessionSettings::class)->graceMinutes());
    }

    /**
     * The moment a seat with no ping becomes Absent.
     *
     * Measured from the scheduled start, not from when the room happened to
     * open: a teacher who opens late must not push every student's deadline out
     * with them.
     */
    public function absenceThresholdAt(): CarbonInterface
    {
        return $this->starts_at->copy()->addSeconds(
            app(SessionSettings::class)->absenceThresholdSeconds($this),
        );
    }

    /** The last moment a booking may be cancelled without being charged. */
    public function cancellationDeadline(): CarbonInterface
    {
        return $this->starts_at->copy()->subMinutes(
            app(SessionSettings::class)->cancellationWindowMinutes(),
        );
    }

    /** Whether the room accepts anyone at this moment (FR-015). */
    public function joinWindowCovers(DateTimeInterface $moment): bool
    {
        if ($this->room_closed_at !== null) {
            return false;
        }

        $window = app(SessionSettings::class)->joinWindowMinutes();

        return $moment >= $this->starts_at->copy()->subMinutes($window)
            && $moment <= $this->ends_at->copy()->addMinutes($window);
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }
}
