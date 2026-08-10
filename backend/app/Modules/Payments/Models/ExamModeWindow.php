<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A period in which deferral is switched off: the floor is forced to zero
 * whatever the credit limit says (FR-046).
 *
 * Workspace-owned — a window over the teacher's own calendar.
 *
 * @property CarbonInterface $starts_on restated because Larastan reads the
 *                                      migration's raw DATE column and would hand the cast's Carbon back as
 *                                      a string
 * @property CarbonInterface $ends_on
 * @property-read Workspace $workspace workspace_id is NOT NULL
 */
class ExamModeWindow extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    protected $fillable = [
        'workspace_id',
        'starts_on',
        'ends_on',
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

    /**
     * Windows covering the given moment.
     *
     * Compared against date STRINGS, never with whereDate(): a function around
     * the column discards the (workspace_id, starts_on, ends_on) index, and this
     * is consulted on every booking. Same fix FreezePeriod::covering() needed in
     * 005.
     *
     * ⚠️ AND THE LOWER BOUND IS `< NEXT DAY`, NOT `<= TODAY`. The column is
     * declared DATE, but Eloquent writes a date-cast attribute through the
     * model's datetime format, so what is stored is `2026-08-09 00:00:00`. MySQL
     * truncates that on insert and the naive form appears to work; SQLite keeps
     * the string, and `'2026-08-09 00:00:00' <= '2026-08-09'` is FALSE — a window
     * covering today would match nothing, in the test suite only, which is the
     * worst possible place for it to be wrong. `FreezePeriod::covering()` carried
     * the identical defect and was fixed with it.
     *
     * Both halves stay plain column comparisons, so the index survives.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCovering(Builder $query, Carbon $moment): Builder
    {
        $day = $moment->toDateString();
        $nextDay = $moment->copy()->addDay()->toDateString();

        return $query->where('starts_on', '<', $nextDay)
            ->where('ends_on', '>=', $day);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
