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
        // and every absentee sweep.
        //
        // ⚠️ THE LOWER BOUND IS `< NEXT DAY`, NOT `<= TODAY`, AND THAT IS A FIX.
        // The columns are declared DATE, but Eloquent writes a date-cast
        // attribute through the model's datetime format, so the stored value is
        // `2026-08-09 00:00:00`. MySQL truncates that on insert into a DATE
        // column and the old `<= '2026-08-09'` held; SQLite keeps the string, and
        // `'2026-08-09 00:00:00' <= '2026-08-09'` is FALSE — so a freeze covering
        // today matched NOTHING, every test that asserted a freeze blocked a
        // booking was asserting against zero rows, and only production behaved.
        // Found while writing spec 006's exam-mode window, whose scope is this one.
        //
        // The next-day form is correct whichever way the value was stored, and
        // both halves stay plain column comparisons, so the index survives.
        $moment = CarbonImmutable::instance($moment);
        $day = $moment->toDateString();
        $nextDay = $moment->addDay()->toDateString();

        return $query
            ->where('starts_on', '<', $nextDay)
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
