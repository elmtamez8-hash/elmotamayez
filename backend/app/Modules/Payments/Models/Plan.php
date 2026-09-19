<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Payments\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription plan: a stretch of time a teacher sells access for.
 *
 * Genuinely tenant-owned, so it carries BelongsToWorkspace — unlike `coupons`,
 * whose `workspace_id` is a scope on platform reference data. The consequence is
 * the usual one: the trait protects nothing on a STUDENT's path (a student is a
 * member of no workspace, `WorkspaceContext::id()` is null and
 * `WorkspaceScope::apply()` adds no condition), so the catalogue and purchase
 * Actions resolve the uuid themselves with `withoutWorkspaceScope()` and the
 * explicit guards below. Never bind `{plan}` implicitly on a student route.
 *
 * `ClassSessionType` is imported from LiveSessions for the same reason
 * `CreditPackage` imports it: it is a two-case value enum, and a second
 * individual/group vocabulary owned by Payments drifts the day either module
 * adds a third kind of room.
 *
 * ⚠️ A PLAN HAS TWO SHAPES (٠٣٦ · FR-020): a stretch of days, or a number of
 * sessions. Both columns are nullable and EXACTLY ONE is set. The rule is enforced
 * in `SavePlan`, not by the engine — a `CHECK` constraint differs between MySQL
 * and SQLite and tells the teacher nothing about which field to fix. And a reader
 * that treats a null `duration_days` as zero produces a subscription that expires
 * the instant it is activated, which is why both annotations below are nullable
 * rather than left alone: at level 8 the analyser is what walks every comparison
 * that forgot.
 *
 * @property int $workspace_id
 * @property string $title
 * @property int|null $duration_days
 * @property int|null $session_count
 * @property ClassSessionType $session_type
 * @property PlanCoverage $coverage_type
 * @property string|null $coverage_uuid
 * @property int|null $price_minor
 * @property string $currency
 * @property bool $is_active
 */
class Plan extends BaseModel
{
    /** @use HasFactory<PlanFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'title',
        'duration_days',
        'session_count',
        'session_type',
        'coverage_type',
        'coverage_uuid',
        'currency',
        'is_active',
    ];

    /*
    | ⚠️ `price_minor` IS DELIBERATELY ABSENT FROM $fillable, and this is the
    | `captured_order_id` rule reached from a second direction. FR-025 splits one
    | row between two actors: the teacher writes the duration and the coverage,
    | the platform writes the price. `SavePlan` takes a whole array from a
    | teacher's form — mass-assignable, the price is one extra key in a request
    | body away from a teacher setting the platform's number, and no form filter
    | shapes the NEXT request. It is written by `SetPlanPrice` alone, explicitly.
    */

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'session_count' => 'integer',
            'session_type' => ClassSessionType::class,
            'coverage_type' => PlanCoverage::class,
            'price_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Whether this row may be sold at all.
     *
     * ⚠️ AN UNPRICED PLAN IS NOT A FREE PLAN. Between the teacher creating it and
     * an officer pricing it the column is null, and `(int) null === 0` — so a
     * catalogue that filtered on `is_active` alone would offer a subscription for
     * nothing, take an order for zero, and activate it on approval.
     */
    public function isSellable(): bool
    {
        return $this->is_active && $this->price_minor !== null;
    }

    /**
     * The plans a student may actually be shown.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNotNull('price_minor');
    }

    /**
     * The catalogue's reading order (036 . T075).
     *
     * A plain `orderBy('duration_days')` was the whole ordering, and once that
     * column turned nullable it stopped saying anything: NULL sorts FIRST in
     * ascending order on MySQL and on SQLite alike, so every plan sold by
     * SESSIONS jumped to the top of every list -- the teacher's own screen and
     * the buyer's -- ahead of the month plan that is the ordinary thing to sell,
     * for no reason a reader could see.
     *
     * So the SHAPE is the first key and the number is the second: the plans that
     * sell time, shortest first, then the plans that sell sessions, fewest first.
     *
     * The CASE is written out rather than leaning on either engine's null
     * placement, because `NULLS LAST` is a Postgres spelling and MySQL's answer
     * to `ORDER BY col DESC` is the opposite of SQLite's for the same rows.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeOrderedByShape(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN duration_days IS NULL THEN 1 ELSE 0 END')
            ->orderBy('duration_days')
            ->orderBy('session_count');
    }
}
