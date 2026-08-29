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
 * @property int $workspace_id
 * @property string $title
 * @property int $duration_days
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
}
