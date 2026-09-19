<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\PlanChangeStatus;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher's ask to change a plan the platform has already priced (٠٣٦).
 *
 * ⚠️ IT CARRIES BOTH SIDES OF EVERY FIELD, AND THAT IS THE POINT RATHER THAN
 * BOOKKEEPING. The approval writes a NEW plan and switches the old one off, so a
 * month later the row this request names has moved on — and «what exactly did
 * the officer agree to» cannot be reconstructed from anything still live. Same
 * shape, and the same reason, as `rate_change_requests` in ٠٠٦.
 *
 * ⚠️ TENANT-OWNED, AND THE OFFICER'S READ MUST SAY SO. `BelongsToWorkspace`
 * means a platform-wide queue has to declare `withoutWorkspaceScope()`, or it
 * silently shows only the requests of whatever workspace the officer's own
 * `users.last_workspace_id` happens to name — which is ٠٢٤'s defect, on a screen
 * whose whole job is to be platform-wide.
 *
 * @property int $workspace_id
 * @property int $plan_id
 * @property int|null $current_duration_days
 * @property int|null $current_session_count
 * @property ClassSessionType $current_session_type
 * @property PlanCoverage $current_coverage_type
 * @property string|null $current_coverage_uuid
 * @property int|null $current_price_minor
 * @property int|null $requested_duration_days
 * @property int|null $requested_session_count
 * @property ClassSessionType $requested_session_type
 * @property PlanCoverage $requested_coverage_type
 * @property string|null $requested_coverage_uuid
 * @property int|null $requested_price_minor
 * @property string|null $reason
 * @property PlanChangeStatus $status
 * @property int $requested_by
 * @property int|null $decided_by
 * @property string|null $decision_reason
 * @property int|null $approved_plan_id
 * @property CarbonImmutable|null $requested_at
 * @property CarbonImmutable|null $decided_at
 *
 * ⚠️ `plan` IS NULLABLE AND IS READ AS `$request->plan->title ?? '—'` — a PLAIN
 * arrow inside `??`, never `?->`. `??` uses isset semantics, which tolerate a null
 * base all by themselves, so the nullsafe there is a guard that can never fire and
 * reads to the next person as one that can; and a plain arrow WITHOUT the `??` is
 * a real null dereference. Both wrong forms were written before this one, and the
 * analyser named each in turn.
 *
 * In practice a plan is never deleted — `PlanResource::canDelete()` refuses
 * outright, because `subscriptions.plan_id` points at it and a student's own
 * subscription must keep naming what they bought — so the fallback is a sentence
 * nobody should ever read.
 *
 * `approvedPlan` IS genuinely null — for every request that was not approved,
 * which is most of them — and every reader of it branches.
 * @property-read Plan|null $approvedPlan
 */
class PlanChangeRequest extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    protected $fillable = [
        'workspace_id',
        'plan_id',
        'current_duration_days',
        'current_session_count',
        'current_session_type',
        'current_coverage_type',
        'current_coverage_uuid',
        'current_price_minor',
        'requested_duration_days',
        'requested_session_count',
        'requested_session_type',
        'requested_coverage_type',
        'requested_coverage_uuid',
        'requested_price_minor',
        'reason',
        'status',
        'requested_by',
        'requested_at',
    ];

    /*
    | ⛔ `decided_by`, `decided_at`, `decision_reason` AND `approved_plan_id` ARE
    | DELIBERATELY ABSENT FROM `$fillable`. They are claimed by the decision
    | itself, in one conditional UPDATE — the `captured_order_id` rule. Mass
    | assignable, they become a second way to close a request from outside the
    | transaction that owns it.
    */

    protected function casts(): array
    {
        return [
            'current_duration_days' => 'integer',
            'current_session_count' => 'integer',
            'current_session_type' => ClassSessionType::class,
            'current_coverage_type' => PlanCoverage::class,
            'current_price_minor' => 'integer',
            'requested_duration_days' => 'integer',
            'requested_session_count' => 'integer',
            'requested_session_type' => ClassSessionType::class,
            'requested_coverage_type' => PlanCoverage::class,
            'requested_price_minor' => 'integer',
            'status' => PlanChangeStatus::class,
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function approvedPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'approved_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @param  Builder<PlanChangeRequest>  $query
     * @return Builder<PlanChangeRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PlanChangeStatus::Pending->value);
    }
}
