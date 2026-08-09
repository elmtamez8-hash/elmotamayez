<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A period in which deferral is switched off: the floor is forced to zero
 * whatever the credit limit says (FR-046).
 *
 * Workspace-owned — a window over the teacher's own calendar.
 *
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
     * Both columns here are DATE, so `<= $day` is correct — the
     * start-of-next-day rule applies when the stored column is a timestamp and
     * the bound is a date.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCovering(Builder $query, Carbon $moment): Builder
    {
        $day = $moment->toDateString();

        return $query->where('starts_on', '<=', $day)
            ->where('ends_on', '>=', $day);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
