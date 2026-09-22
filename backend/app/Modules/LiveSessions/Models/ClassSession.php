<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Models\MediaAsset;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\Carbon;
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
 * @property CarbonInterface|null $recording_attempted_at
 * @property int|null $attended_seats ٠٣٥ — for DISPLAY (FR-015أ)
 * @property int|null $charged_seats ٠٣٥ — what the TEACHER IS PAID ON (FR-014)
 * @property int|null $verdict_stay_seconds the bar ACTUALLY APPLIED, so moving
 *                                          the setting cannot re-judge the past (SC-012)
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
        // Copied from the course at scheduling time, not read through it: the
        // settlement rate and the purchase price must resolve from identical
        // inputs, and a course whose grade changes later must not reprice
        // sessions already taught.
        'grade_level',
        'charged_at',
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
        'recording_attempted_at',
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
            // ٠٣٥ — frozen at close by ONE conditional UPDATE, and deliberately
            // absent from $fillable and from UpdateClassSessionRequest: the
            // `captured_order_id` rule. Mass-assignable, `charged_seats` is a
            // second door through which a teacher writes their own wage with a
            // PUT. NULL means «not computed yet», never zero.
            'attended_seats' => 'integer',
            'charged_seats' => 'integer',
            'verdict_stay_seconds' => 'integer',
            'seats_frozen_at' => 'datetime',
            'room_opened_at' => 'datetime',
            'room_closed_at' => 'datetime',
            'recording_attempts' => 'integer',
            'recording_attempted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            // Set when the seats were charged (006). Null on a delivered session
            // is the "delivered but never billed" set the sweep repairs — and
            // that set is unavoidable, because CloseClassSession returns early on
            // a terminal status, so SessionDelivered fires exactly once, ever.
            'charged_at' => 'datetime',
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

    /**
     * Does this person hold a seat here — of ANY status?
     *
     * ⚠️ DELIBERATELY WIDER THAN THE DOOR. `RoomRevocation::isHost()` asks for a
     * `Booked` seat, because entering a room is a live entitlement. This answers
     * "is this session any of your business", which a cancelled seat also settles:
     * the student whose enrolment lapsed and whose seat `ReleaseIneligibleBookings`
     * then cancelled is precisely the person `FR-038` exists for, and a
     * booked-only read would refuse them the sentence explaining why.
     *
     * The workspace scope is dropped for the same reason `recordingLesson()` drops
     * it: the reader is a student, whose own context is not this teacher's
     * workspace and is usually no workspace at all.
     */
    /**
     * The session behind a door a STUDENT walks through — resolved without the
     * workspace scope, then authorised on the next line by the caller.
     *
     * ⛔ NOT IMPLICIT BINDING. `WorkspaceContext::id()` falls back to
     * `users.last_workspace_id`, which is stamped on every student a teacher ever
     * added to their workspace — so the scope ANDs the OTHER teacher's id and the
     * student's own booked lesson answers 404. Spec 032's fix for courses and
     * enrolments, reached from LiveSessions. The teacher-only routes (`update`,
     * `cancel`, `host`, `feedback`) keep implicit binding: there the scope IS the
     * tenant guard.
     */
    public static function forStudentDoor(string $uuid): self
    {
        return self::query()->withoutWorkspaceScope()->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * What a student-facing Resource reads, loaded WITHOUT the scope.
     *
     * ⚠️ `withoutWorkspaceScope()` on the parent query does not reach a relation:
     * each one runs its own model's global scope, so for a student stamped with
     * another teacher's workspace `bookings` loads empty — `my_booking` goes null
     * and the page stops recognising the seat — and `recordingLesson` loads null,
     * hiding the recording. One list, so the three student reads cannot drift.
     *
     * @return array<string, \Closure>
     */
    public static function studentEagerLoads(): array
    {
        $unscoped = static fn ($query) => $query->withoutWorkspaceScope();

        return ['course' => $unscoped, 'bookings' => $unscoped, 'recordingLesson' => $unscoped];
    }

    public function holdsSeat(User $user): bool
    {
        return $this->bookings()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->exists();
    }

    /**
     * Did this person's seat here carry a financial right?
     *
     * ٠٣٥ · T063. The sibling above answers «is this session any of your
     * business», which a cancelled seat settles too. This answers the narrower
     * money question: `Booked` or `CancelledLate` — the seat that is, or was,
     * paid for. A seat cancelled inside the window and one released by
     * `ReleaseIneligibleBookings` are both outside it, because neither was ever
     * charged.
     *
     * ⚠️ TWO QUESTIONS, TWO PREDICATES, AND THE WIDER ONE IS NOT NARROWED. The
     * precedent is one file away: `EloquentSessionAttendanceDirectory` keeps
     * `ENTITLING` and `occupiesSeat()` apart for exactly this reason — sharing a
     * constant is how the second answer quietly becomes the first.
     *
     * ⛔ AND IT HAS NO READER YET. Written on the owner's instruction
     * (2026-09-13) rather than the day its caller was written, which is this
     * repository's rule; `SessionContentController::entitled()` is the nearest
     * money-shaped door and its width is DELIBERATE — a student who cancelled in
     * time may still buy the hour, and narrowing it would shut a door they hold
     * the price of. Anyone reaching for this: prove your door is not that one.
     */
    public function heldBillableSeat(User $user): bool
    {
        return $this->bookings()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->whereIn('status', [BookingStatus::Booked, BookingStatus::CancelledLate])
            ->exists();
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

    /**
     * When the billable seat count is settled (027 · FR-039ب).
     *
     * Normally the cancellation deadline — the moment the count stops being able
     * to change. But a session SCHEDULED INSIDE ITS OWN CANCELLATION WINDOW has
     * a deadline in the past, so freezing at it settles the count at zero
     * before anybody could book: the teacher then delivers a full lesson and is
     * paid for nobody, whether the seats were taken by hand or by a
     * subscription. Such a session has no cancellation window at all, so the
     * count settles when the lesson starts.
     *
     * ⚠️ Measured from `created_at`, never from `now()`: this is a question
     * about the session's birth and must give the same answer every time it is
     * asked. A freshly built model has no `created_at` yet, so the current
     * moment stands in for it — the only case where they are the same thing.
     */
    public function billableSeatsFreezeAt(): CarbonInterface
    {
        $deadline = $this->cancellationDeadline();
        $bornAt = $this->created_at ?? now();

        return $deadline->greaterThan($bornAt) ? $deadline : $this->starts_at->copy();
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

    /**
     * How many seconds until the door opens — `0` for open now, `null` for never
     * again (a closed room, or a window already past).
     *
     * ⚠️ IT LIVES ON THE MODEL BECAUSE TWO CALLERS ASK IT. `ScheduleController`
     * carried this as a private helper for the course header, and 029's timetable
     * card needs the same number — a second copy is the two-spellings defect this
     * repository has paid for with `BookingEligibility`'s host check and with
     * `ListLeaderboardScopes`, and here the two copies would drift on the day an
     * operator moves the window.
     *
     * ⚠️ AND IT IS WHY A JOIN BUTTON APPEARS ON A PAGE LEFT OPEN. `join_open` is
     * answered once, at fetch, so without a number to tick down a student who
     * opens their timetable twenty minutes early watches the countdown reach
     * «بدأت الآن» while the door stays shut until they reload. The browser may
     * tick a number down; it may never derive one from a clock that may be an
     * hour out (SC-016), and the window itself is a `platform_settings` row.
     */
    public function secondsUntilJoinOpen(DateTimeInterface $moment): ?int
    {
        if ($this->room_closed_at !== null) {
            return null;
        }

        $window = app(SessionSettings::class)->joinWindowMinutes();

        if ($moment > $this->ends_at->copy()->addMinutes($window)) {
            return null;
        }

        return max(0, (int) Carbon::instance($moment)->diffInSeconds(
            $this->starts_at->copy()->subMinutes($window),
            false,
        ));
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }
}
