<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\Modules\LiveSessions\FreezePeriodFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stretch of days where nothing counts.
 *
 * One table for both scopes: `student_user_id === null` freezes every student of
 * the teacher, a value freezes one (FR-039). They differ in who they cover, not
 * in what they do, and two tables would duplicate every guard.
 *
 * The period never writes to attendance rows or counters — it is *read* by
 * scheduling, booking and the counting jobs (research §R11). That is why
 * resuming afterwards cannot fail: there is nothing to undo.
 *
 * @property CarbonInterface $starts_on
 * @property CarbonInterface $ends_on
 */
class FreezePeriod extends BaseModel
{
    /** @use HasFactory<FreezePeriodFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'student_user_id',
        'starts_on',
        'ends_on',
        'reason',
        'created_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Periods covering a moment, for everyone or for this student in particular.
     *
     * @param  Builder<FreezePeriod>  $query
     * @return Builder<FreezePeriod>
     */
    public function scopeCovering(Builder $query, DateTimeInterface $moment, ?int $studentUserId = null): Builder
    {
        // Plain comparisons against a date string, NOT whereDate(): MySQL cannot
        // use `(workspace_id, starts_on, ends_on)` once a function wraps the
        // column, and this scope is consulted on every booking, every schedule
        // and every absentee sweep. The columns are DATE, so comparing against a
        // date string is exact — the cast whereDate() performs is the one thing
        // being paid for and the one thing not needed.
        $day = CarbonImmutable::instance($moment)->toDateString();

        return $query
            ->where('starts_on', '<=', $day)
            ->where('ends_on', '>=', $day)
            ->where(function (Builder $scope) use ($studentUserId): void {
                // A workspace-wide freeze covers this student too; a freeze on a
                // different student does not.
                $scope->whereNull('student_user_id');

                if ($studentUserId !== null) {
                    $scope->orWhere('student_user_id', $studentUserId);
                }
            });
    }
}
