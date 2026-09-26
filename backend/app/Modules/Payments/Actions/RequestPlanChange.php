<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\PlanChangeStatus;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\PlanChangeRequest;
use App\Shared\Actions\Action;
use DomainException;

/**
 * The teacher asks. Nothing on the plan moves yet (٠٣٦, owner decision 2026-09-19).
 *
 * ⛔ THIS IS THE WAY OUT OF `SavePlan`'s REFUSAL, AND THE TWO ARE ONE DESIGN.
 * A priced plan may not have its shape or coverage moved by a teacher, because
 * that moves what the platform priced out from under the price. A refusal with
 * no way through is a teacher opening a support ticket every time — or worse,
 * writing a second plan and leaving the first one on sale.
 *
 * ⚠️ THE SHAPE RULE IS ASKED HERE TOO, AND IT IS NOT DUPLICATION. A request that
 * names both shapes, or neither, is a request the officer cannot approve — and
 * discovering that at the decision is discovering it in front of the wrong
 * person, days later. `SavePlan::describeShape()` is the one spelling; this asks
 * it of the REQUESTED values before anything is written.
 *
 * ⚠️ AND A SECOND PENDING REQUEST FOR ONE PLAN IS REFUSED. Two asks about one
 * row are two decisions that can disagree, and the second approval would write a
 * third plan from a snapshot taken before the first one landed.
 */
class RequestPlanChange extends Action
{
    public function __construct(private readonly SavePlan $plans) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $author, Plan $plan, array $data): PlanChangeRequest
    {
        if ($plan->price_minor === null) {
            /*
            | ⛔ AN UNPRICED PLAN NEEDS NO REQUEST, AND OFFERING ONE WOULD BE A
            | QUEUE TO NOWHERE. Nothing has been priced yet, so `SavePlan` lets
            | the teacher write it directly — and a request here would sit in the
            | officer's queue asking permission for something already permitted.
            */
            throw new DomainException('هذه الباقة لم تُسعَّر بعد، فعدّلها من شاشتك مباشرة.');
        }

        if (PlanChangeRequest::query()->where('plan_id', $plan->getKey())->pending()->exists()) {
            throw new DomainException('لهذه الباقة طلب تعديل قيد المراجعة بالفعل.');
        }

        $coverage = $data['coverage_type'] instanceof PlanCoverage
            ? $data['coverage_type']
            : PlanCoverage::from((string) $data['coverage_type']);

        $sessionType = $data['session_type'] instanceof ClassSessionType
            ? $data['session_type']
            : ClassSessionType::from((string) $data['session_type']);

        // ⚠️ THE WRITER'S OWN SPELLING, asked of the REQUESTED values.
        [$duration, $sessionCount] = $this->plans->resolveShape($data, $coverage, $sessionType);

        $coverageUuid = $this->plans->resolveCoverage($coverage, $data['coverage_uuid'] ?? null, (int) $plan->workspace_id, $plan->coverage_uuid);

        $price = $this->positiveOrNull($data['requested_price_minor'] ?? null);

        return PlanChangeRequest::query()->create([
            'workspace_id' => (int) $plan->workspace_id,
            'plan_id' => (int) $plan->getKey(),

            // ⚠️ BOTH SIDES, WRITTEN NOW. The approval switches this plan off and
            // writes a new one, so a month from now nothing live still carries
            // what the officer was actually looking at.
            'current_duration_days' => $plan->duration_days,
            'current_session_count' => $plan->session_count,
            'current_session_type' => $plan->session_type,
            'current_coverage_type' => $plan->coverage_type,
            'current_coverage_uuid' => $plan->coverage_uuid,
            'current_price_minor' => $plan->price_minor,

            'requested_duration_days' => $duration,
            'requested_session_count' => $sessionCount,
            'requested_session_type' => $sessionType,
            'requested_coverage_type' => $coverage,
            'requested_coverage_uuid' => $coverageUuid,
            /*
            | ⚠️ NULL IS «THE PLATFORM DECIDES», NEVER ZERO. Pricing is the
            | platform's half of the row (FR-025); a teacher may ask for a new
            | shape and leave the number to whoever sets numbers. Zero here would
            | be read as «free», which nobody means.
            */
            'requested_price_minor' => $price,

            'reason' => $this->trimmed($data['reason'] ?? null),
            'status' => PlanChangeStatus::Pending,
            'requested_by' => (int) $author->getKey(),
            'requested_at' => now(),
        ]);
    }

    private function positiveOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (int) $value;

        return $number < 1 ? null : $number;
    }

    private function trimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
