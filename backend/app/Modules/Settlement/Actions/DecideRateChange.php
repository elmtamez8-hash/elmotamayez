<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Models\User;
use App\Modules\Settlement\Enums\RateRequestStatus;
use App\Modules\Settlement\Events\SettlementRateApproved;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementRate;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Approval is the ONLY thing in the system that writes a settlement rate.
 *
 * Not a convention anyone has to remember — the only writer. That is what makes
 * "no rate takes effect without approval" (SC-005أ) a property of the code
 * rather than a rule a reviewer has to keep noticing, and it is why there is no
 * `SettlementRate::create()` anywhere else in the module.
 *
 * The new row is INSERTED with its own effective date. The old row is left
 * exactly as it was, so every hour already taught keeps the price it was taught
 * at (FR-011) — an UPDATE here would reprice the past with one keystroke.
 */
class DecideRateChange extends Action
{
    public function approve(RateChangeRequest $request, User $by): SettlementRate
    {
        $this->refuseIfDecided($request);

        return DB::transaction(function () use ($request, $by): SettlementRate {
            $rate = SettlementRate::query()->create([
                'workspace_id' => (int) $request->workspace_id,
                'teacher_profile_id' => (int) $request->teacher_profile_id,
                'session_type' => $request->session_type,
                'subject_id' => $request->subject_id,
                'grade_level' => $request->grade_level,
                'amount_minor' => $request->requested_amount_minor,
                'currency' => (string) $request->currency,
                // From now, never backdated. FR-011 has no exception, and an
                // "effective from" the admin could type is the exception.
                'effective_from' => now(),
                'approved_by' => $by->getKey(),
                'rate_change_request_id' => (int) $request->getKey(),
            ]);

            $request->forceFill([
                'status' => RateRequestStatus::Approved,
                'decided_by' => $by->getKey(),
                'decided_at' => now(),
            ])->save();

            // Consumed by the cost-plus pricing in 006, which does not exist
            // yet. Declared with its deferred consumer in contracts/events.md
            // rather than left for a review to find, which is how this phase
            // inherited SessionDelivered and how 005 lost SessionCancelled.
            SettlementRateApproved::dispatch($rate);

            return $rate;
        });
    }

    public function reject(RateChangeRequest $request, User $by, string $reason): RateChangeRequest
    {
        $this->refuseIfDecided($request);

        if (trim($reason) === '') {
            // FR-013أ — "no" with no reason is a request the teacher will simply
            // file again next week, and a rule nobody can learn.
            throw new DomainException('اذكر سبب الرفض.');
        }

        $request->forceFill([
            'status' => RateRequestStatus::Rejected,
            'decided_by' => $by->getKey(),
            'decided_at' => now(),
            'decision_reason' => $reason,
        ])->save();

        return $request;
    }

    private function refuseIfDecided(RateChangeRequest $request): void
    {
        if ($request->status !== RateRequestStatus::Pending) {
            throw new DomainException('هذا الطلب مقرَّر سلفاً.');
        }
    }
}
