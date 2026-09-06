<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Jobs\ClaimSubscriptionSeatsJob;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Data\SubscriptionIntent;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Events\Contracts\CarriesPaidOrder;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\EffectiveSubscriptionEnd;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\CohortScheduleDirectory;
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
        private readonly JoinCohort $join,
        private readonly CohortDirectory $cohorts,
        private readonly CohortScheduleDirectory $schedules,
        private readonly DispatchNotification $notify,
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
            /*
            | ⚠️ THE RETRY CONTINUES, IT DOES NOT RETURN. This used to `return`
            | here, and that single line made a partial activation PERMANENT and
            | SILENT: a throw anywhere below left the subscription committed, and
            | every later attempt hit `unique(order_id)`, read the existing row,
            | got null back — and stopped one step before the access it never
            | opened. The student had paid, held a subscription, and was in no
            | course and no group, for ever, with only the first attempt in
            | `failed_jobs`.
            |
            | FR-027 asks that approval be a whole, and it is satisfied by
            | CONVERGENCE rather than by atomicity: every step below is
            | re-runnable (`EnrollStudent` is `firstOrCreate`, membership skips a
            | student already in the group, the seat job re-runs), so a second
            | attempt finishes the job instead of skipping it. An outer
            | transaction was the other candidate and is worse — `after_commit`
            | is false on every connection, so it would push `NotifyStudentEnrolled`
            | about an enrolment that can still roll back.
            */
            $subscription = Subscription::query()
                ->withoutWorkspaceScope()
                ->where('order_id', $order->getKey())
                ->first();

            if ($subscription === null) {
                return;
            }
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

        $this->joinCohort($order, $subscription);

        $this->announceActivation($order, $subscription);
    }

    /**
     * The group the student paid to be in (027 · FR-025), and the seats it owes
     * them (FR-039).
     *
     * ⚠️ AFTER `openAccess()`, NEVER BEFORE IT. `JoinCohort` refuses a student
     * with no active enrolment in the course, and the enrolment is what
     * `openAccess()` has just written — for the cohort's OWN course, which a
     * workspace-wide plan covers alongside several others.
     *
     * ⚠️ AND «ALREADY IN THIS GROUP» IS A SUCCESS. `JoinCohort` throws
     * `alreadyMember()` for any open membership in the course, so a renewal —
     * the ordinary case, and what US4·4 promises must need no new choice — would
     * throw HERE, after the money committed, retry, throw again, and end in
     * `failed_jobs` with nothing on the officer's screen. The membership is asked
     * first, in the same order `ApproveOrder` and `PurchaseSubscription` ask it.
     */
    private function joinCohort(Order $order, Subscription $subscription): void
    {
        $intent = SubscriptionIntent::fromOrder($order);

        if ($intent === null || ! $intent->isCohort() || $intent->cohortUuid === null) {
            return;
        }

        $student = $order->user;
        $described = $this->cohorts->describeGroupCohort($intent->cohortUuid);

        if ($described === null) {
            Log::warning('027: an activated subscription names a group that no longer resolves', [
                'order_id' => $order->getKey(),
                'cohort_uuid' => $intent->cohortUuid,
            ]);

            return;
        }

        $current = $this->cohorts->openMembershipCohortId($student, (int) $described['course_id']);

        if ($current !== null && $current !== (int) $described['id']) {
            // `ApproveOrder` refuses this before the money moves; reaching it
            // here means the membership changed inside the activation itself.
            // Moving them is not ours to decide, so the group is left alone and
            // the seats are claimed for the group they are actually in.
            Log::warning('027: the student joined another group between approval and activation', [
                'order_id' => $order->getKey(),
            ]);

            return;
        }

        if ($current === null) {
            $cohort = Cohort::query()
                ->withoutWorkspaceScope()
                ->whereKey($described['id'])
                ->first();

            if ($cohort === null) {
                return;
            }

            $this->workspace->forWorkspace(
                (int) $described['workspace_id'],
                fn (): mixed => $this->join->handle($cohort, $student),
            );
        }

        /*
        | ⚠️ `afterCommit()`, AND IT MATTERS EVEN THOUGH NOTHING HERE OPENS A
        | TRANSACTION. `config/queue.php` sets `after_commit => false` on all four
        | connections, so a job pushed inside one reaches a worker in
        | milliseconds — and the first thing the seat claim asks is whether the
        | student has an active enrolment. Uncommitted, the answer is no, EVERY
        | session is refused, one notice names them all, and the paid month has no
        | seat in it while the job reports success. With no transaction open the
        | callback simply runs at once, so this costs nothing and stays correct if
        | anybody ever wraps the steps above.
        */
        ClaimSubscriptionSeatsJob::dispatch(
            (int) $described['workspace_id'],
            (int) $student->getKey(),
            (int) $described['course_id'],
            (int) $described['id'],
            CarbonImmutable::parse($subscription->effective_ends_on)->toDateString(),
        )->afterCommit();
    }

    /**
     * «فُعِّل اشتراكك، وهذه مواعيدك، وهذا الباب» (027 · FR-029 · FR-029أ · FR-030).
     *
     * ⚠️ THE SCHEDULE GOES OUT IN BOTH FORMS. The weekly rhythm («السبت ٥م») is
     * what a guardian organises the week around and does not say WHICH Saturday;
     * the next lesson's date says which day and hides the rhythm. One without the
     * other is a message that produces the question it was sent to answer.
     *
     * ⚠️ AND «no lesson scheduled yet» IS SAID OUT LOUD (FR-029أ). An omitted line
     * reads as a fault — the student refreshes, finds nothing, and asks whether
     * their payment worked.
     *
     * ⚠️ AND THE VARIABLES ARE BUILT IN A FIXED ORDER, NEVER BY WALKING A PAYLOAD.
     * The provider's approved template numbers its placeholders, so the order IS
     * the meaning: a list assembled from whatever key order a listener happened to
     * write puts the teacher's name where the date belongs, on a parent's phone,
     * with nothing reporting an error.
     *
     * ⚠️ EVERY READ HERE GOES THROUGH A CONTRACT THAT DECLARES ITS OWN SCOPE
     * BYPASS. This runs on a worker with no workspace context, and a null from a
     * scoped relation AFTER the money committed is a 500 with the payment already
     * taken — the fourth layer of the 024 defect.
     */
    private function announceActivation(Order $order, Subscription $subscription): void
    {
        $student = $order->user;
        $intent = SubscriptionIntent::fromOrder($order);

        if ($intent === null) {
            return;
        }

        $schedule = 'حصص خاصة: مواعيد مدرّسك صارت مفتوحة لطلب حصة.';
        $nextSession = 'لم تُجدول حصة قادمة بعد؛ ستصلك رسالة فور جدولتها.';
        $actionUrl = '/schedule';

        if ($intent->isCohort() && $intent->cohortUuid !== null) {
            $described = $this->cohorts->describeGroupCohort($intent->cohortUuid);

            if ($described !== null) {
                $slots = $this->schedules->schedulePreviewFor([$described['id']])[$described['id']] ?? [];

                $schedule = $slots === []
                    ? sprintf('مجموعة «%s» — لم تُعلَن مواعيدها الأسبوعية بعد.', $described['name'])
                    : sprintf('مجموعة «%s» — %s.', $described['name'], implode(' · ', $slots));

                $next = $this->schedules->nextSessionFor((int) $described['id']);

                if ($next !== null) {
                    $nextSession = sprintf('أقرب حصة: %s.', $next['starts_at']);
                    $actionUrl = '/sessions/'.$next['uuid'].'/room';
                }
            }
        }

        $this->notify->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::SubscriptionActivated,
            variables: [
                'plan_title' => $intent->planTitle,
                'teacher_name' => $intent->teacherName ?? 'مدرّسك',
                'starts_on' => CarbonImmutable::parse($subscription->starts_on)->toDateString(),
                'ends_on' => CarbonImmutable::parse($subscription->effective_ends_on)->toDateString(),
                'schedule' => $schedule,
                'next_session' => $nextSession,
            ],
            actionUrl: $actionUrl,
            subject: $student,
            workspaceId: (int) $subscription->workspace_id,
        ));
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

        /*
        | ⚠️ THE DURATION COMES FROM THE ORDER'S SNAPSHOT, NOT FROM THE PLAN. A
        | manual transfer takes days to clear, and a teacher may legitimately
        | re-duration the plan inside that lag — the officer's queue prints the
        | snapshot, so reading the live plan here sells one number to the officer
        | and another to the student. Exactly the argument the price above already
        | won. The plan stands in only when the order carries no snapshot at all
        | (an order placed before 027 shipped).
        */
        $intent = SubscriptionIntent::fromOrder($order);
        $ends = $starts->addDays($intent === null ? (int) $plan->duration_days : $intent->durationDays);

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
