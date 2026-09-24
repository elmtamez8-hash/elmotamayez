<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\LiveSessions\Enums\AttendanceSource;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\LiveSessions\AttendanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's standing in one session.
 *
 * A bridge row. Reading it is guarded three ways: the student owns theirs, a
 * guardian sees their own child's and nobody else's (FR-023ب), and a teacher
 * sees only sessions in their own workspace (NFR-001أ).
 *
 * `auto_status` is kept alongside `status` on purpose: an override must never
 * erase what the system worked out (FR-025). A register that hides having been
 * edited is trusted more than it has earned.
 *
 * @property AttendanceStatus $status
 * @property AttendanceStatus|null $auto_status
 * @property AttendanceSource $source
 * @property CarbonInterface|null $first_joined_at
 * @property CarbonInterface|null $last_ping_at
 * @property CarbonInterface|null $confirmed_at
 * @property CarbonInterface|null $report_sent_at
 * @property CarbonInterface|null $recording_watched_at
 * @property CarbonInterface|null $overridden_at
 * @property CarbonInterface|null $credit_verdict_at stamped ⇒ THIS SEAT WAS
 *                                                   CHARGED, which is not the same as «the stay reached the
 *                                                   bar»: FR-008ج charges the silent no-show too, and the four
 *                                                   exemptions (excused · removed · notified · never
 *                                                   delivered) are judged in `CloseClassSession` and frozen
 *                                                   here. It is read as «charged» by the billing side and as
 *                                                   «receives the hour» by the content gate, because those are
 *                                                   one set. Null on a JUDGED session means exempt; the
 *                                                   «not judged yet» branch is `class_sessions.attended_seats
 *                                                   IS NULL`, read from the SESSION.
 */
class Attendance extends BaseModel
{
    /** @use HasFactory<AttendanceFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'class_session_id',
        'student_user_id',
        'status',
        'source',
        'first_joined_at',
        'last_ping_at',
        // ⚠️ `stay_seconds` IS DELIBERATELY ABSENT SINCE ٠٣٥, and removing the
        // `->default(0)` from its migration is what would break three callers.
        // Measured: `RecordPresencePing.php:70`, `CloseClassSession.php:129`
        // and `MarkAbsenteesJob.php:75` all pass `'stay_seconds' => 0` inside a
        // `firstOrCreate` attributes array — i.e. through mass assignment,
        // which now discards the key in silence and lands on the column
        // default. They survive BECAUSE of that default.
        //
        // It is out because the column became MONEY with ٠٣٥: the seat is
        // charged when the stay reaches the bar, so any bulk update written
        // tomorrow is a teacher writing a deduction against their own student.
        // Every legitimate writer uses `forceFill()` and the arithmetic in
        // `RecordPresencePing`, which is the one spelling of it.
        'auto_status',
        'overridden_by',
        'overridden_at',
        'override_reason',
        'confirmed_at',
        'removed_at',
        'report_sent_at',
        'recording_watched_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'auto_status' => AttendanceStatus::class,
            'source' => AttendanceSource::class,
            'first_joined_at' => 'datetime',
            'last_ping_at' => 'datetime',
            'stay_seconds' => 'integer',
            'overridden_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'removed_at' => 'datetime',
            // ٠٣٥ — written inside the close loop's existing `forceFill`, and
            // deliberately not fillable for the reason `stay_seconds` left.
            'credit_verdict_at' => 'datetime',
            'report_sent_at' => 'datetime',
            // Claimed by a conditional UPDATE in `SendAbsenceAlerts`, never
            // mass-assigned — the `captured_order_id` rule.
            'absence_alerted_at' => 'datetime',
            'recording_watched_at' => 'datetime',
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

    public function wasOverridden(): bool
    {
        return $this->overridden_at !== null;
    }

    /**
     * The register, without the person teaching it.
     *
     * ⚠️ THE HOST HAS A ROW HERE ON PURPOSE, AND IT IS NOT A STUDENT ROW.
     *
     * `CloseClassSession` judges delivery — the teacher's pay — from the host's
     * own attendance row, so the heartbeat records them like anyone else and
     * removing that row would stop every session from being billable. What must
     * not happen is showing it as a line in the class roll: on 2026-08-18 the
     * first real session put «Demo Teacher» in the teacher's own register,
     * marked absent, beside a manual-attendance control and a note field
     * labelled «تصل وليّ الأمر مع تقرير الحصة».
     *
     * `SendSessionReportsJob` already knew this and skipped the row in a loop.
     * One rule in two places is how the third caller gets it wrong, so it lives
     * here now and both read it.
     *
     * @param  Builder<Attendance>  $query
     */
    public function scopeExcludingHost(Builder $query, ClassSession $session): void
    {
        $hostUserId = $session->teacherProfile?->user_id;

        // A session with no teacher profile has no host to exclude — filtering
        // on null would empty the register instead of leaving it whole.
        if ($hostUserId !== null) {
            $query->where('student_user_id', '!=', $hostUserId);
        }
    }
}
