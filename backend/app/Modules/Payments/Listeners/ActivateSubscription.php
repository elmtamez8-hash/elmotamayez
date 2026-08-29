<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Events\Contracts\CarriesPaidOrder;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\EffectiveSubscriptionEnd;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * The money is witnessed; the subscription starts (T092 · FR-026).
 *
 * ⚠️ TYPED ON THE CONTRACT, NOT ON `PaymentApproved`. A manual approval and a
 * gateway capture are two doors onto one payment, and binding a listener to one
 * of them leaves every subscription bought through the other silently unpaid-for
 * — the exact defect `CreateEnrollmentFromOrder` threw a TypeError over on the
 * first real gateway payment.
 *
 * ⚠️ `ShouldHandleEventsAfterCommit`, BECAUSE `ApproveOrder` FIRES FROM INSIDE ITS
 * OWN TRANSACTION. Without it this job is queued while that transaction is still
 * open, a worker picks it up in milliseconds and reads the order as `pending` —
 * or does not find it at all. The student has paid and got nothing, with no
 * retry, because the job "succeeded".
 *
 * ⚠️ THE IDEMPOTENCY GUARD IS `unique(order_id)`, AND IT IS THE ONLY ONE
 * AVAILABLE. A redelivered event, a retried job or an operator replaying a
 * payment would otherwise write a SECOND active subscription against one
 * payment — access doubled in length, invisible on every screen, and the ledger
 * perfectly balanced beside it. Never a `->exists()` check followed by a create:
 * that is a read and a write with the race between them.
 *
 * ⚠️ AND THE ENROLMENT IS WHAT ACTUALLY OPENS ANYTHING. `expires_at` on an
 * enrolment is a data field in this tree and gates nothing — nothing reads it —
 * so access is opened by an `active` enrolment and closed by
 * `ExpireSubscriptionsJob` moving that row to `expired`. Both are found again by
 * `(order_id, source = 'subscription')`, which is also what keeps a student's
 * PRE-EXISTING enrolment — a course they bought outright — out of the expiry
 * sweep: `firstOrCreate` returns it untouched, so it carries neither marker.
 *
 * ⚠️ KNOWN CEILING, WRITTEN DOWN: a `workspace` plan enrols the student in the
 * courses that teacher has PUBLISHED AT ACTIVATION. A course published later in
 * the month is inside the plan's coverage as far as
 * {@see App\Modules\Payments\Support\SubscriptionEligibility} is concerned, and
 * has no enrolment row, so its content stays shut. Closing it needs a
 * `CoursePublished` event, which does not exist in this tree yet; add the
 * listener beside this one when it does.
 */
class ActivateSubscription implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly EnrollStudent $enroll,
        private readonly EffectiveSubscriptionEnd $ends,
        private readonly WorkspaceContext $workspace,
    ) {}

    public function handle(CarriesPaidOrder $event): void
    {
        $order = $event->order();

        if ($order->kind !== OrderKind::Subscription) {
            return;
        }

        $plan = $this->planFor($order);

        if ($plan === null) {
            // Nothing to derive a duration from, and guessing one would sell a
            // month nobody agreed to. Logged rather than thrown: the payment is
            // real and the other listeners on this event must still run.
            Log::warning('011: an approved subscription order names no plan', [
                'order_id' => $order->getKey(),
            ]);

            return;
        }

        $subscription = $this->claim($order, $plan);

        if ($subscription === null) {
            return;
        }

        /*
        | ⚠️ THE FOURTH RECOMPUTE MOMENT (T095). A subscription activated INSIDE a
        | freeze that is already running would otherwise be born without the
        | extension it is owed — and nothing later would fix it, because the
        | other three moments are all writes to `freeze_periods` and that period
        | already exists.
        */
        $subscription->forceFill([
            'effective_ends_on' => $this->ends->forSubscription($subscription),
        ])->save();

        $this->openAccess($order, $plan, $subscription);
    }

    /**
     * The plan this order was placed against.
     *
     * Carried on `orders.metadata` rather than in a column of its own: the order
     * table is shared by four kinds and a `plan_id` on it would be null for three
     * of them. Re-resolved by uuid WITHOUT the sellable filter — the student paid
     * for a plan that was on sale when they bought it, and a teacher switching it
     * off in the meantime must not swallow their money.
     */
    private function planFor(Order $order): ?Plan
    {
        $uuid = $order->metadata['plan_uuid'] ?? null;

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return Plan::query()->withoutWorkspaceScope()->where('uuid', $uuid)->first();
    }

    /**
     * Write the subscription, or discover somebody already did.
     *
     * ⚠️ THE CATCH DISTINGUISHES A DUPLICATE FROM A FAILURE, and a bare
     * `catch (QueryException) { return null; }` would not: a null, a foreign key
     * or an out-of-range value would then be read as «already recorded» and the
     * subscription would never exist, with the payment approved and nothing
     * logged. The read-back is what tells the two apart.
     */
    private function claim(Order $order, Plan $plan): ?Subscription
    {
        $starts = CarbonImmutable::today();
        $ends = $starts->addDays((int) $plan->duration_days);

        try {
            return Subscription::create([
                // Explicit, from the plan: `BelongsToWorkspace` fills nothing
                // here — this runs on a queue with no workspace context at all,
                // and the buyer is a student who belongs to no workspace either.
                'workspace_id' => $plan->workspace_id,
                'plan_id' => $plan->getKey(),
                'student_user_id' => $order->user_id,
                'order_id' => $order->getKey(),
                // ⚠️ FROM THE ORDER, NEVER FROM THE PLAN (FR-030). A manual
                // transfer takes days to clear and the plan can legitimately be
                // repriced inside that lag, so the plan's price today is not the
                // number this student was shown and paid.
                'price_minor' => (int) $order->amount_minor,
                'currency' => $order->currency,
                'starts_on' => $starts,
                'ends_on' => $ends,
                'effective_ends_on' => $ends,
                'status' => SubscriptionStatus::Active,
            ]);
        } catch (QueryException $e) {
            $existing = Subscription::query()
                ->withoutWorkspaceScope()
                ->where('order_id', $order->getKey())
                ->first();

            if ($existing === null) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Enrol the student in what the plan covers.
     *
     * ⚠️ `expires_at` IS STAMPED ONLY ON A ROW THIS CREATED. `EnrollStudent` is
     * `firstOrCreate`, so a student who already bought this course outright gets
     * their existing, open-ended enrolment back — and stamping an expiry on it
     * would revoke permanent access they paid for, a month later, silently.
     */
    private function openAccess(Order $order, Plan $plan, Subscription $subscription): void
    {
        foreach ($this->coveredCourses($plan) as $course) {
            $enrollment = $this->workspace->forWorkspace(
                (int) $course->workspace_id,
                fn () => $this->enroll->handle(
                    course: $course,
                    student: $order->user,
                    source: 'subscription',
                    orderId: (int) $order->getKey(),
                ),
            );

            if ($enrollment->wasRecentlyCreated) {
                $enrollment->forceFill([
                    'expires_at' => CarbonImmutable::parse($subscription->effective_ends_on)->endOfDay(),
                ])->save();
            }
        }
    }

    /**
     * @return EloquentCollection<int, Course>
     */
    private function coveredCourses(Plan $plan): EloquentCollection
    {
        return Course::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $plan->workspace_id)
            ->where('status', 'published')
            ->when(
                $plan->coverage_type->needsCourse(),
                fn ($query) => $query->where('uuid', $plan->coverage_uuid),
            )
            ->get();
    }
}
