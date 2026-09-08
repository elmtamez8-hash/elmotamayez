<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\CostPlusPricing;
use App\Modules\Payments\Support\CourseParticipation;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\DiscountResolver;
use App\Modules\Payments\Support\StopSellingGuard;
use App\Shared\Actions\Action;
use App\Shared\Scopes\WorkspaceScope;
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
        private readonly DiscountResolver $discounts,
        private readonly RedeemCoupon $redeem,
    ) {}

    public function handle(
        User $student,
        Course $course,
        CreditPackage $package,
        ?string $couponCode = null,
        ?User $grantedBy = null,
    ): CreditPurchase {
        /*
        | ⚠️ THE SKIP IS PARTIAL, AND SKIPPING THE WHOLE THING IS THE DEFECT.
        |
        | `isPartyTo()` answers TWO questions in one call: the seller refusal
        | first, then the ways in — an active enrolment, or membership as a
        | STUDENT. Spec 024 lets a platform officer buy on a student's behalf,
        | and the ways in are exactly what a brand-new student has none of: a
        | real student is a member of no workspace at all (only `AcceptInvitation`
        | and `CreateWorkspace` write that pivot), so their first purchase would
        | be refused for ever. Hence the skip.
        |
        | But the REFUSAL must survive it. A grant to the course's own teacher
        | hands them their own totals for two package sizes, which solve for the
        | platform's two constants — and every OTHER teacher's approved settlement
        | rate follows from any other course's total (FR-021ب). So `isSeller()` is
        | asked on both paths, and the student's path keeps `isPartyTo()` as its
        | single spelling with its own message unchanged.
        */
        if ($grantedBy === null) {
            if (! $this->participation->isPartyTo($student, $course)) {
                throw new AuthorizationException('لا يمكنك شراء أرصدة على كورس لست طرفاً فيه.');
            }
        } elseif ($this->participation->isSeller($student, $course)) {
            throw new AuthorizationException('لا يمكن منح أرصدة لمن يدرّس هذا الكورس.');
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

        /*
        | ⚠️ THE DISCOUNT MOVES `orders.amount_minor` AND LEAVES THE SNAPSHOT
        | ALONE (T067 · FR-010). `credit_purchases` records what the four parts of
        | the price WERE at the moment of sale — the teacher's approved rate among
        | them — and the settlement close reads that snapshot to decide what the
        | teacher is owed. Discounting it would take a platform campaign out of a
        | teacher's pay, which is the one thing FR-010 forbids by name.
        |
        | So the two numbers are allowed to differ, deliberately: the order is
        | what the buyer transfers, the snapshot is what the sale was worth. The
        | difference is the platform's, and it is recorded on the redemption row.
        */
        $discount = $this->discounts->resolve(
            $student,
            (int) $course->workspace_id,
            $price->totalMinor,
            $couponCode,
            CouponScope::CreditPackage,
            (string) $package->uuid,
        );

        return DB::transaction(function () use ($student, $course, $package, $balance, $price, $discount, $grantedBy): CreditPurchase {
            $order = Order::create([
                'workspace_id' => $course->workspace_id,
                'user_id' => $student->getKey(),
                'course_id' => $course->getKey(),
                'kind' => OrderKind::Credits,
                'amount_minor' => $price->totalMinor - $discount->amountMinor,
                'currency' => $price->currency,
                'provider' => 'manual',
                'status' => 'pending',
            ]);

            if ($grantedBy !== null) {
                /*
                | NOT `$fillable`, on purpose — see the model. It is an audit fact
                | written HERE, once, and never again: re-stamping it on a later
                | edit would move a recorded act to whoever last touched the row.
                */
                $order->forceFill(['granted_by' => $grantedBy->getKey()])->save();
            }

            // Inside the transaction: a ceiling claimed out from under this
            // purchase throws, and the rollback stops an order existing at a
            // price the coupon no longer justifies.
            $this->redeem->handle($order, $discount);

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
    /**
     * Credits already bought on this balance that nobody has decided yet.
     *
     * ⛔ THIS COUNTED `'pending'` AND NOTHING ELSE, SO PAYING EMPTIED THE CEILING.
     * `UploadPaymentReceipt` moves the order to `under_review`; the row then fell
     * out of this sum, the cap read as free, and the same student could buy it
     * again — and again — with `ApproveOrder` minting from every one of them. The
     * loop is two ordinary steps, not an exploit: order, upload, repeat.
     *
     * `Order::isPending()` held the correct pair one file away the whole time, but
     * it is an INSTANCE method and cannot appear inside a subquery — which is why
     * the fix is a scope both of them read, rather than a sixth hand-written copy
     * of `['pending','under_review']`.
     *
     * ⚠️ AND THE BYPASS IS REPEATED INSIDE THE SUBQUERY. `withoutWorkspaceScope()`
     * on the line above applies to `CreditPurchase` and to nothing else — the
     * bypass is per model, and `whereHas('order', …)` builds a fresh `Order` query
     * that carries `Order`'s own `BelongsToWorkspace`. Inert for a student in
     * production (a member of no workspace resolves a null context), and NOT inert
     * for a platform officer or a guardian who owns a workspace: their context
     * falls back to `users.last_workspace_id`, the subquery matches nothing, and
     * the ceiling silently counts zero. That is live on the officer's grant screen
     * today. Measuring it needs TWO workspaces and an actor who belongs to one.
     */
    private function pendingCreditsOn(CreditBalance $balance): int
    {
        return (int) CreditPurchase::query()
            ->withoutWorkspaceScope()
            ->where('credit_balance_id', $balance->getKey())
            /*
            | ⚠️ THE MACRO AND THE SCOPE ARE BOTH SPELLED OUT HERE, DELIBERATELY.
            | Inside a `whereHas` closure the builder is `Builder<Model>`, so neither
            | `withoutWorkspaceScope()` (a macro on the model's own builder) nor
            | `awaitingDecision()` (a local scope) is visible to static analysis.
            | `WorkspaceScope::extend()` defines the first as exactly this call, and
            | the status list stays a single source of truth as a method on `Order` —
            | which is the whole point: the four typed call sites read the scope, and
            | this one reads the same list the scope is built from.
            */
            ->whereHas('order', fn (Builder $query) => $query
                ->withoutGlobalScope(WorkspaceScope::class)
                ->whereIn('status', Order::awaitingDecisionStatuses()))
            ->sum('credits');
    }
}
