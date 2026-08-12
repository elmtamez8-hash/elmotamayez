<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\CostPlusPricing;
use App\Modules\Payments\Support\CourseParticipation;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\StopSellingGuard;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Start a credit purchase: a pending order and its price snapshot.
 *
 * ⚠️ NOT ONE CREDIT IS ADDED HERE (FR-018). This writes an order awaiting a
 * receipt and the four-part snapshot beside it; the balance moves only when
 * PaymentApproved reaches {@see RecordCreditPurchase}. Minting on intent would
 * hand out sessions to anyone who could press a button.
 *
 * The snapshot is written HERE, at the moment of purchase, and never recomputed
 * (FR-020 · FR-021ز · FR-021ح). A teacher's rate approved between this row and
 * its approval must not reprice a purchase already made — the shortfall that
 * creates is a decided loss shown on the approval screen (Q-7), not a silent
 * one discovered at close.
 */
class PurchaseCredits extends Action
{
    public function __construct(
        private readonly CostPlusPricing $pricing,
        private readonly StopSellingGuard $sales,
        private readonly CourseParticipation $participation,
        private readonly CreditAccounts $accounts,
        private readonly BillingSettings $settings,
    ) {}

    public function handle(User $student, Course $course, CreditPackage $package): CreditPurchase
    {
        if (! $this->participation->isPartyTo($student, $course)) {
            throw new AuthorizationException('لا يمكنك شراء أرصدة على كورس لست طرفاً فيه.');
        }

        $refusal = $this->sales->refusalToSell($course);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        if (! $package->is_active) {
            // Checked against the row rather than trusting the listing: the
            // screen was rendered at some earlier moment, and a package retired
            // in between must not still be buyable from a stale tab.
            throw new DomainException('هذه الحزمة لم تعد متاحة.');
        }

        $balance = $this->accounts->balanceFor($student, $course);

        /*
        | The unredeemed ceiling (FR-021ي · Q-11).
        |
        | The platform holds this money until the sessions are delivered, and
        | getting money back from a teacher who stopped delivering is a human
        | negotiation (Q-11) rather than a transaction. So the worst case is
        | capped by a number instead of by intent: a student may hold this many
        | undelivered sessions on one course and no more.
        |
        | Measured on `remaining_credits`, not on lifetime purchases — credits
        | already consumed are sessions already delivered and are nobody's
        | exposure.
        |
        | ⚠️ AND ON PENDING PURCHASES BESIDE IT, which the first version missed.
        | Nothing mints until approval (FR-018), so `remaining_credits` is still
        | zero while an order awaits its receipt: a student at zero with a
        | ceiling of 6 could post two purchases of 6 back to back, pass both —
        | each measured against an untouched balance — and end up holding 12 when
        | the payments were approved days apart. The mint path has no ceiling
        | check either, by design: refusing there would take the money and refuse
        | the credits. So the count has to include what is already promised.
        */
        $ceiling = $this->settings->maxUnredeemedCredits();
        $held = max(0, $balance->remaining_credits) + $this->pendingCreditsOn($balance);

        if ($held + $package->credits > $ceiling) {
            throw new DomainException(sprintf(
                'الحد الأقصى للأرصدة غير المستهلَكة على هذا الكورس %d، ولديك %d (بما في ذلك طلبات لم تُعتمَد بعد). اشترِ حزمة أصغر أو استهلك ما لديك أولاً.',
                $ceiling,
                $held,
            ));
        }

        $price = $this->pricing->price($package, (int) $course->getKey(), now());

        if ($price === null) {
            // No approved rate means no price, and no price means no sale
            // (FR-021ز). A default here is a number nobody approved, charged to
            // a student and owed to a teacher who never agreed to it.
            throw new DomainException('لا يمكن تسعير هذا الكورس حالياً، فالشراء غير متاح.');
        }

        return DB::transaction(function () use ($student, $course, $package, $balance, $price): CreditPurchase {
            $order = Order::create([
                'workspace_id' => $course->workspace_id,
                'user_id' => $student->getKey(),
                'course_id' => $course->getKey(),
                'kind' => OrderKind::Credits,
                'amount_minor' => $price->totalMinor,
                'currency' => $price->currency,
                'provider' => 'manual',
                'status' => 'pending',
            ]);

            return CreditPurchase::create([
                'credit_balance_id' => $balance->getKey(),
                'credit_package_id' => $package->getKey(),
                'course_id' => $course->getKey(),
                'workspace_id' => $course->workspace_id,
                'order_id' => $order->getKey(),
                // Copied, not read through the package later: disabling a package
                // must not touch credits bought from it (FR-019).
                'credits' => $package->credits,
                'teacher_rate_minor' => $price->teacherRateMinor,
                'operating_fee_minor' => $price->operatingFeeMinor,
                'gateway_fee_minor' => $price->gatewayFeeMinor,
                'total_minor' => $price->totalMinor,
                'currency' => $price->currency,
                'purchased_at' => now(),
            ]);
        });
    }

    /**
     * Credits bought on this balance whose payment has not been decided yet.
     *
     * Pending only: a rejected order will never mint, and an approved one is
     * already in `remaining_credits` — counting either would refuse a purchase
     * over credits that do not and will not exist.
     */
    private function pendingCreditsOn(CreditBalance $balance): int
    {
        return (int) CreditPurchase::query()
            ->withoutWorkspaceScope()
            ->where('credit_balance_id', $balance->getKey())
            ->whereHas('order', fn (Builder $query) => $query->where('status', 'pending'))
            ->sum('credits');
    }
}
