<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Payments\SubscriptionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's live subscription to one plan (data-model §٨).
 *
 * @property int $workspace_id
 * @property int $plan_id
 * @property int $student_user_id
 * @property int $order_id
 * @property int $price_minor
 * @property CarbonInterface $starts_on
 * @property CarbonInterface $ends_on
 * @property CarbonInterface $effective_ends_on
 * @property SubscriptionStatus $status
 *
 * ⚠️ The two claimed timestamps are cast to dates and were typed as the raw
 * column, so every reader of them was a string as far as the analyser knew — and
 * `->diffForHumans()` on one is an error nobody sees until a screen finally reads
 * it. They are not `$fillable` (both are claimed by conditional UPDATEs); being
 * un-fillable is not a reason to leave them undeclared.
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $expiring_notified_at
 */
class Subscription extends BaseModel
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'plan_id',
        'student_user_id',
        'order_id',
        'price_minor',
        'currency',
        'starts_on',
        'ends_on',
        'effective_ends_on',
        'status',
    ];

    /*
    | `expiring_notified_at` and `cancelled_at` are NOT fillable: both are
    | claimed by conditional UPDATEs (the notice, so a second sweep cannot
    | re-send it; the cancellation, so a double tap cannot reverse one payment
    | twice). Mass-assignable, either becomes a second way to claim a lock from
    | outside the statement that owns it.
    */

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'effective_ends_on' => 'date',
            'status' => SubscriptionStatus::class,
            'expiring_notified_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Active, and the clock has not run out.
     *
     * ⚠️ NEITHER BOUND IS EVER WRITTEN `<= $dateString`, AND THIS IS THE READ
     * PATH — pinning the comparison in the nightly sweep alone is half a fix.
     * Eloquent writes a date-cast attribute through the model's datetime format,
     * so SQLite stores `2026-09-30 00:00:00` in a column MySQL truncates to
     * `2026-09-30`. `'2026-09-30 00:00:00' <= '2026-09-30'` is FALSE, so the
     * obvious spelling silently drops the boundary day on one engine only:
     * access dying at midnight on the morning of the day the card says it ends,
     * and a subscription bought today granting nothing until tomorrow.
     *
     * So both bounds are written in the direction the suffix cannot break —
     * `< nextDay` and `>= today`, the complement of the sweep's own `< today`.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeLiveOn(Builder $query, DateTimeInterface $moment): Builder
    {
        $today = CarbonImmutable::instance($moment)->toDateString();
        $tomorrow = CarbonImmutable::instance($moment)->addDay()->toDateString();

        return $query
            ->where('status', SubscriptionStatus::Active->value)
            ->where('starts_on', '<', $tomorrow)
            ->where('effective_ends_on', '>=', $today);
    }
}
