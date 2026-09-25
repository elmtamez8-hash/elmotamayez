<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Learning\Actions\MoveMember;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Jobs\ClaimSubscriptionSeatsJob;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Data\SubscriptionIntent;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Events\Contracts\CarriesPaidOrder;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\CoveredCourses;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Payments\Support\EffectiveSubscriptionEnd;
use App\Modules\Payments\Support\PlanLineage;
use App\Modules\Payments\Support\SubscriptionAccess;
use App\Modules\Payments\Support\SubscriptionDays;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\CohortScheduleDirectory;
use App\Shared\Support\CountedNoun;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
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
 * ⚠️ `ShouldQueueAfterCommit`, BECAUSE `ApproveOrder` FIRES FROM INSIDE ITS
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
class ActivateSubscription implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    /**
     * The idempotency key's type half for an hours purchase (٠٣٦ · T066).
     *
     * ⚠️ ≤ 32 CHARACTERS AND DISTINCT FROM `credit_purchase`. `source_type` is
     * part of `credit_tx_idempotency` — `(credit_balance_id, type, source_type,
     * source_id)` — so two shapes sharing one type would collide the moment a
     * package row and an order row happen to share an id.
     *
     * ⚠️ AND IT IS PUBLIC BECAUSE TWO READERS ASK FOR IT BY NAME: the auditor's
     * chain behind one payment and the nightly reconciliation. Spelled out at
     * either of those sites instead, an hours sale reads as a sale that minted
     * nothing — which is exactly what both of them shipped doing.
     */
    public const CREDIT_SOURCE_TYPE = 'session_plan_order';

    /**
     * The enrolment source for the hours shape (٠٣٦ · T067).
     *
     * ⛔ NOT `subscription`. `SubscriptionAccess::close()` closes every enrolment
     * matching `(order_id, source = subscription)` — and there is no
     * subscription row behind this order, so that sweep would be closing access
     * nothing was ever going to re-open.
     */
    private const ENROLMENT_SOURCE = 'session_plan';

    public function __construct(
        private readonly EnrollStudent $enroll,
        private readonly MoveMember $move,
        private readonly CohortDirectory $cohorts,
        private readonly CohortScheduleDirectory $schedules,
        private readonly DispatchNotification $notify,
        private readonly EffectiveSubscriptionEnd $ends,
        private readonly WorkspaceContext $workspace,
        private readonly CoveredCourses $covered,
        // 036 - the second shape: credits in 035's ledger, no subscription row.
        private readonly CreditAccounts $accounts,
        private readonly CreditLedger $ledger,
        // The platform's calendar, never UTC's: see `SubscriptionDays`.
        private readonly SubscriptionDays $days,
        private readonly PlanLineage $lineage,
    ) {}

    public function handle(CarriesPaidOrder $event): void
    {
        $order = $event->order();

        if ($order->kind !== OrderKind::Subscription) {
            return;
        }

        // Reversed between the approval and this worker: no month, no hours,
        // no enrolment — `Order::wasWithdrawn()` says why it is re-read.
        if ($order->wasWithdrawn()) {
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

        /*
        | ٠٣٦ — A SHAPE WITH NO WINDOW IS NOT A SUBSCRIPTION ROW, AND THIS IS THE
        | HALF OF IT THAT EXISTS SO FAR. A plan sold by the hour carries no
        | `duration_days`, so there is no end date to write and `subscriptions`
        | is the wrong table for it entirely — the hours belong in the credit
        | ledger, which is T064's arm and is not built yet.
        |
        | ⛔ IT MUST NOT FALL THROUGH TO `addDays(0)`. That writes a row whose end
        | date equals its start date: a subscription that expired the instant it
        | was activated, after the student paid, with every screen correct about
        | an empty window and nothing logged anywhere. Logged rather than thrown,
        | in the shape the branch above already uses — the payment is real and
        | every other listener on this event must still run.
        */
        /*
        | ⛔ THE SHAPE DECIDES WHICH TABLE THIS IS (٠٣٦ · T064), AND IT IS READ
        | FROM THE ORDER'S SNAPSHOT — never from the plan row. A manual transfer
        | takes days to clear and the teacher may legitimately re-shape the plan
        | inside that lag; everything else about this order already reads the
        | snapshot for that reason, and the officer's queue prints it.
        |
        | A plan sold by the HOUR writes no `subscriptions` row at all: there is
        | no window to store, and a row with a made-up end date is a promise
        | nobody made. The hours go into ٠٣٥'s ledger, which is where the product
        | already knows how to spend them.
        */
        $intent = SubscriptionIntent::fromOrder($order);

        if ($intent !== null && $intent->isSessionShaped()) {
            $this->activateSessionPlan($order, $plan, $intent);

            return;
        }

        /*
        | ⚠️ THE OLD ARM, AND IT IS GUARDED RATHER THAN TRUSTED. An order placed
        | before ٠٢٧ carries no snapshot at all and falls through to the live
        | plan — which may by now be a session plan with a null duration. «No
        | window ⇒ record and return», never `addDays(0)`.
        */
        $window = $this->windowFor($order, $plan);

        if ($window === null) {
            Log::warning('٠٣٦: طلب اشتراك بلا مدّة ولا لقطة شكل', [
                'order_id' => $order->getKey(),
                'plan_id' => $plan->getKey(),
            ]);

            return;
        }

        $subscription = $this->claim($order, $plan, $window);

        // Whether THIS run created the subscription — the one run allowed to
        // give a place back (see `joinCohort()`).
        $firstActivation = $subscription !== null;

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

        $this->openAccess(
            $order,
            $plan,
            // The last instant of the last paid day IN DOHA, stored as UTC —
            // `->endOfDay()` on the bare date closed it at 03:00 the next morning.
            $this->days->endOf(CarbonImmutable::parse($subscription->effective_ends_on)),
            'subscription',
        );

        $this->joinCohort(
            $order,
            CarbonImmutable::parse($subscription->effective_ends_on)->toDateString(),
            releaseClaimedPlace: $firstActivation,
        );

        $this->announceActivation($order, $subscription);
    }

    /**
     * The group the student paid to be in (027 · FR-025), and the seats it owes
     * them (FR-039).
     *
     * ⚠️ AFTER `openAccess()`, NEVER BEFORE IT. `MoveMember` refuses a student
     * with no active enrolment in the course, and the enrolment is what
     * `openAccess()` has just written — for the cohort's OWN course, which a
     * workspace-wide plan covers alongside several others.
     *
     * ⚠️ AND «ALREADY IN THIS GROUP» IS A SUCCESS. The writer throws
     * `sameCohort()` for a student already in this very group, so a renewal —
     * the ordinary case, and what US4·4 promises must need no new choice — would
     * throw HERE, after the money committed, retry, throw again, and end in
     * `failed_jobs` with nothing on the officer's screen. The membership is asked
     * first, in the same order `ApproveOrder` and `PurchaseSubscription` ask it.
     *
     * ⚠️ **AND THE ACTION IS `MoveMember`, NOT `JoinCohort` — CHANGED BY ٠٣٤ ·
     * `T027`.** The old call wrote `joined` with the STUDENT as actor, so the
     * history said «the student joined» about a membership the platform's own
     * approval created: `SC-002` failed for every subscription however correct the
     * implementation. The event is now DERIVED inside the writer's transaction,
     * and the actor is the officer who approved.
     *
     * @param  bool  $releaseClaimedPlace  true only on the activation that CREATED
     *                                     the subscription (or, for the hours
     *                                     shape, posted the hours) — a retry must
     *                                     not give `ApproveOrder`'s place back a
     *                                     second time
     */
    private function joinCohort(Order $order, ?string $seatWindowEnd, bool $releaseClaimedPlace = false): void
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

        // The group whose lessons the paid window buys seats in: the one the
        // order named, unless the student is somewhere else by now.
        $seatCohortId = (int) $described['id'];

        if ($current !== null && $current !== (int) $described['id']) {
            // `ApproveOrder` refuses this before the money moves; reaching it
            // here means the membership changed inside the activation itself.
            // Moving them is not ours to decide, so the group is left alone and
            // the seats are claimed for the group they are actually in.
            Log::warning('027: the student joined another group between approval and activation', [
                'order_id' => $order->getKey(),
            ]);

            /*
            | ⛔ THIS USED TO `return` HERE, ONE LINE UNDER THE COMMENT ABOVE —
            | so the seats were claimed for NO group, and the paid month had no
            | lesson in it. And the place `ApproveOrder` claimed in the group the
            | order named stayed taken by nobody: that group read one member more
            | than it had, for ever, and turned the last real applicant away as
            | «full». The place goes back once — on the first activation only,
            | because a retried activation reaches this line again and the
            | decrement is not a recount — and the seats follow the student.
            */
            if ($releaseClaimedPlace && $order->approved_by !== null) {
                $this->cohorts->releaseSeat((int) $described['id']);
            }

            $seatCohortId = $current;
        }

        if ($current === null) {
            $cohort = Cohort::query()
                ->withoutWorkspaceScope()
                ->whereKey($described['id'])
                ->first();

            if ($cohort === null) {
                return;
            }

            /*
            | ⛔ **فاعلُ السجلِّ هو الموظَّفُ المُعتمِدُ، والحدثُ `assigned`**
            | (٠٣٤ · `T027` · قرارُ المالك). كانَ `JoinCohort` يكتبُ `joined`
            | بفاعلٍ هو الطالب — فالسجلُّ يقولُ «انضمَّ الطالب» عن عضويّةٍ
            | أنشأَها اعتمادُ الإدارة، و`SC-002` يفشلُ دائماً مهما صحَّ التنفيذ.
            | و`MoveMember` **يشتقُّ الحدثَ داخلَ المعاملة** فلا يُملَى من هنا.
            |
            | ⚠️ **و`requireOpen: false` مقصودٌ**: الطالبُ اختارَ مجموعةً
            | مفتوحةً ودفعَ، والمقعدُ مُطالَبٌ به سلفاً — فإغلاقُ المدرّسِ
            | للبابِ بينَ الاعتمادِ والتفعيلِ لا يجوزُ أن يترُكَ طالباً دفعَ
            | بلا مجموعة ومقعدُه محجوز.
            |
            | ⚠️ **و`seatAlreadyClaimed` مشروطةٌ لا مطلَقة** (`T021أ`):
            | `ApproveOrder` طالبَ بالمقعدِ قبلَ قبضِ المال (FR-024أ)، فزيادةٌ
            | ثانيةٌ هنا تُسرِّبُ مقعداً في كلِّ اشتراك **وقد تُرفَضُ
            | بـ«مكتملة» بعدَ أن قُبِضَ المال** — لكنّه وحدَه يُطالِبُ، والشرطُ
            | مكتوبٌ عندَ التمريرِ أدناه. و«وحدَه» في وصفِ `T021أ` بائتٌ: هذا
            | ثاني مُمرِّرٍ للراية.
            */
            $this->workspace->forWorkspace(
                (int) $described['workspace_id'],
                fn (): mixed => $this->move->handle(
                    $cohort,
                    $student,
                    $order->approver ?? $student,
                    /*
                    | ⛔ **مشروطةٌ بأنّ اعتماداً يدويّاً وقعَ فعلاً، لا `true`
                    | مطلَقة.** `ApproveOrder` وحدَه يكتبُ `approved_by`،
                    | و**بابُ بوّابةِ الدفعِ لا يمرُّ به** — وصفُه مكتوبٌ في
                    | `ApproveOrder` منذُ ٠٢٧: «`PaymentCaptured` لا يمرُّ من
                    | هنا». فرايةٌ مطلَقةٌ كانت ستكتبُ عضويّةً **والعدّادُ لم
                    | يزدْ قطّ**: المجموعةُ تقرأُ مقعداً شاغراً لا وجودَ له،
                    | ويُوضَعُ الطالبُ التالي فوقَ السعة — وهو تسرّبُ `T021أ`
                    | نفسُه من الجهةِ المقابلة.
                    |
                    | ⚠️ **وفجوةٌ تُعلَّمُ ولا تُصلَحُ هنا**: على بابِ البوّابةِ
                    | لا مُعتمِدَ، فيقعُ الفاعلُ على الطالبِ ويُقرَأُ السطرُ
                    | «أُسنِدَ» بفاعلٍ هو هو. لا طلبَ اشتراكٍ يمرُّ من ذلكَ
                    | البابِ اليومَ (كلُّها `manual`)، وإصلاحُه قرارُ مواصفةٍ
                    | عن «مَن الفاعلُ حينَ لا إنسانَ» لم تطلبْه ٠٣٤.
                    */
                    seatAlreadyClaimed: $order->approved_by !== null,
                ),
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
        /*
        | ⛔ NO WINDOW ⇒ NO AUTOMATIC SEAT CLAIM AT ALL (٠٣٦ · T115), AND THIS IS
        | AN ENGINEERING DECISION RATHER THAN AN OMISSION. The claim job was
        | written for the buyer of a MONTH: its window-end argument is not
        | nullable, and with no time limit it would book **every future session
        | of the group** — twelve hours bought buying forty chairs. Worse, each
        | of those seats passes through «covered by a subscription» and therefore
        | skips the credit hold, on a comment that says «a subscriber holds no
        | credits» — and the buyer of hours holds exactly that. The result is
        | seats with no hold behind them, charged at attendance.
        |
        | So an hours buyer books by hand, through the ordinary door, where ٠٣٥'s
        | hold is placed for them like anybody else's.
        */
        if ($seatWindowEnd === null) {
            return;
        }

        ClaimSubscriptionSeatsJob::dispatch(
            (int) $described['workspace_id'],
            (int) $student->getKey(),
            (int) $described['course_id'],
            $seatCohortId,
            $seatWindowEnd,
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
                    $nextSession = sprintf('أقرب حصة: %s.', $next['label']);
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
     * The HOURS shape: credits in ٠٣٥'s ledger, and **zero rows** in `subscriptions`.
     *
     * ⛔ THE CREDITS LAND ON THE GROUP'S OWN COURSE BALANCE (٠٣٦ · T065). ٠٣٥
     * keeps one balance per COURSE, deliberately — «+10 in maths and −6 in
     * physics» is not +4 — so «the student's balance» is not a place anything can
     * be put. A plan that names no single course puts them nowhere, and says so
     * rather than guessing.
     *
     * ⛔ AND THE MOVEMENT CARRIES AN IDEMPOTENCY KEY. `credit_transactions` is
     * unique on FOUR columns, so a source left empty prevents no duplicate at
     * all: a redelivered approval pours the hours in a second time, the balance
     * stops equalling the sum of its entries, and nothing notices until the
     * nightly reconciliation a week later.
     *
     * ⛔ AND «ALREADY RECORDED» CONTINUES, IT DOES NOT RETURN. The ledger answers
     * null for a duplicate; returning there would make a redelivery skip the
     * enrolment, the membership and the message — the exact failure the
     * subscription arm above documents at length, reached through the other
     * shape.
     */
    private function activateSessionPlan(Order $order, Plan $plan, SubscriptionIntent $intent): void
    {
        $course = $this->creditCourseFor($order, $plan, $intent);

        if ($course === null) {
            // Logged rather than thrown: the payment is real and the other
            // listeners on this event must still run. Nothing is written, so a
            // retry with the coverage repaired finishes the job.
            Log::warning('٠٣٦: باقة حصص لا تحمل كورساً يُصَبُّ عليه الرصيد', [
                'order_id' => $order->getKey(),
                'plan_id' => $plan->getKey(),
            ]);

            return;
        }

        $balance = $this->accounts->balanceFor($order->user, $course);

        $this->recordSale($order, $plan, $intent, $course, $balance);

        // Null on a redelivery (the idempotency key above). The run that
        // actually posted the hours is the one allowed to give back the place
        // `ApproveOrder` claimed, if the student has changed group since.
        $posted = $this->ledger->post(new CreditMovement(
            balance: $balance,
            type: CreditTransactionType::Purchase,
            credits: (int) $intent->sessionCount,
            sourceType: self::CREDIT_SOURCE_TYPE,
            sourceId: (int) $order->getKey(),
            performedBy: $order->approved_by === null ? null : (int) $order->approved_by,
        ));

        /*
        | ⚠️ NO `expires_at` AND NO SEAT CLAIM. Both take a window and this shape
        | has none — each absence is written down beside the line it changes, in
        | {@see self::openAccess()} and {@see self::joinCohort()}.
        */
        $this->openAccess($order, $plan, null, self::ENROLMENT_SOURCE);

        $this->joinCohort($order, null, releaseClaimedPlace: $posted !== null);

        $this->announceSessionPlan($order, $intent, $course);
    }

    /**
     * The course the hours are credited to.
     *
     * The group the buyer chose settles it; failing that, a course-scoped plan
     * names one itself. A plan covering the whole workspace names none — and
     * FR-022 refuses to let hours be written on that coverage at all, so
     * reaching here with one means a row older than that guard.
     */
    private function creditCourseFor(Order $order, Plan $plan, SubscriptionIntent $intent): ?Course
    {
        $courseId = $order->course_id === null ? null : (int) $order->course_id;

        if ($courseId === null && $intent->cohortUuid !== null) {
            $described = $this->cohorts->describeGroupCohort($intent->cohortUuid);
            $courseId = $described === null ? null : (int) $described['course_id'];
        }

        if ($courseId === null) {
            $courseId = $this->covered->coverageCourseId($plan);
        }

        return $courseId === null
            ? null
            : Course::query()->withoutWorkspaceScope()->whereKey($courseId)->first();
    }

    /**
     * The sale row the finance screen reads (٠٣٦ · T068 · T116).
     *
     * ⛔ WITHOUT IT «CREDIT ALREADY HELD» SWALLOWS «CREDIT JUST SOLD» on the very
     * screen where the platform decides a teacher's price: the balance moves and
     * nothing anywhere records that money came in for it.
     *
     * ⚠️ AND THE THREE FEE COLUMNS STAY NULL. They are what `CostPlusPricing`
     * computes for a PACKAGE; a plan's price is a number a human typed, with no
     * formula to take apart. Zeros there would be read as facts by the books — a
     * teacher who earned nothing on a sale that really happened.
     *
     * Idempotent on the order, like everything else in this listener.
     */
    private function recordSale(
        Order $order,
        Plan $plan,
        SubscriptionIntent $intent,
        Course $course,
        CreditBalance $balance,
    ): void {
        CreditPurchase::query()->withoutWorkspaceScope()->firstOrCreate(
            ['order_id' => (int) $order->getKey()],
            [
                'credit_balance_id' => (int) $balance->getKey(),
                'plan_id' => (int) $plan->getKey(),
                'course_id' => (int) $course->getKey(),
                'workspace_id' => (int) $course->workspace_id,
                'credits' => (int) $intent->sessionCount,
                'total_minor' => (int) $order->amount_minor,
                'currency' => (string) $order->currency,
                'purchased_at' => now(),
            ],
        );
    }

    /**
     * «فُعِّلت باقتك، وهذا رصيدك، وهذه مواعيدك» (٠٣٦ · FR-020 · T117).
     *
     * ⛔ ITS OWN TYPE, NOT `SubscriptionActivated`. That template demands
     * `starts_on` and `ends_on` and throws on any empty variable — so reusing it
     * would either throw after the money committed, or invent two dates the
     * student reads as true about something that ends in HOURS rather than on a
     * day.
     *
     * ⚠️ AND THE COUNT GOES THROUGH `CountedNoun`. Arabic agrees the noun with
     * its number across five bands, so «٢ حصص» is wrong where «حصّتان» is right —
     * the same CLDR rule the frontend's `counted()` reads.
     */
    private function announceSessionPlan(Order $order, SubscriptionIntent $intent, Course $course): void
    {
        $schedule = 'مواعيد مدرّسك مفتوحة لحجز حصصك.';
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
                    $nextSession = sprintf('أقرب حصة: %s.', $next['label']);
                    $actionUrl = '/sessions/'.$next['uuid'].'/room';
                }
            }
        }

        $this->notify->handle(new NotificationRequest(
            recipient: $order->user,
            type: NotificationType::SessionPlanActivated,
            variables: [
                'plan_title' => $intent->planTitle,
                'teacher_name' => $intent->teacherName ?? 'مدرّسك',
                'sessions' => CountedNoun::of((int) $intent->sessionCount, [
                    'one' => 'حصّة واحدة',
                    'two' => 'حصّتان',
                    'few' => 'حصص',
                    'many' => 'حصّة',
                    'other' => 'حصّة',
                ]),
                'schedule' => $schedule,
                'next_session' => $nextSession,
            ],
            actionUrl: $actionUrl,
            subject: $order->user,
            workspaceId: (int) $course->workspace_id,
        ));
    }

    /**
     * How many days this subscription runs for, or `null` when it has no window.
     *
     * ⚠️ THE DURATION COMES FROM THE ORDER'S SNAPSHOT, NOT FROM THE PLAN. A
     * manual transfer takes days to clear, and a teacher may legitimately
     * re-duration the plan inside that lag — the officer's queue prints the
     * snapshot, so reading the live plan here sells one number to the officer and
     * another to the student. Exactly the argument the price already won. The
     * plan stands in only when the order carries no snapshot at all (an order
     * placed before 027 shipped).
     *
     * ⚠️ AND A ZERO IS «NO WINDOW», NOT A WINDOW OF ZERO. Both sources can
     * produce one — a truncated metadata blob, or a plan re-shaped to hours while
     * the transfer cleared — and the whole point of answering `null` is that
     * `addDays(0)` never gets the chance.
     */
    private function windowFor(Order $order, Plan $plan): ?int
    {
        $intent = SubscriptionIntent::fromOrder($order);

        $days = $intent === null
            ? ($plan->duration_days === null ? null : (int) $plan->duration_days)
            : $intent->durationDays;

        return $days !== null && $days > 0 ? $days : null;
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
    private function claim(Order $order, Plan $plan, int $window): ?Subscription
    {
        /*
        | ⛔ THE RENEWAL EXTENDS; IT USED TO START FROM TODAY AND SWALLOW THE REST.
        | Measured on production 2026-09-20 (٠٢٧ · T075): a student holding an
        | active month to 2026-10-16 renewed on the 20th and was given a window
        | from THAT day — 26 paid days overlapped, 600 ر.ق for 34 days instead of
        | 60, the second purchase buying four net days. Owner-decided 2026-09-20:
        | extend from the end of what is still running.
        |
        | ⚠️ AND THE SPEC IS WHY ONLY HALF OF IT WAS BUILT. Scenario 4 writes the
        | renewal as «ومدّتُها تقاربُ الانتهاء», and `today` is exactly right for
        | that case — it loses nothing when nothing is left. The case nobody wrote
        | down is the one that cost money, and `today` was correct code for a
        | requirement that had only been half-asked.
        |
        | ⚠️ KEYED ON THE PLAN, NOT ON THE WORKSPACE. A student may hold a month
        | of maths and buy a month of physics from the same teacher; chaining on
        | the workspace would push the physics month a month into the future —
        | the same defect wearing the opposite sign. The known ceiling is written
        | rather than hidden: a plan RETIRED and replaced by `RequestPlanChange`
        | carries a new `plan_id`, so a renewal onto the replacement starts from
        | today again. That is the FR-030 lag case and it is not solved here.
        |
        | ⚠️ AND `active()` IS DELIBERATELY NOT USED. That scope also asks
        | `starts_on < tomorrow`, which hides a renewal already dated into the
        | future — so a third purchase would chain onto the first and land inside
        | the second. The question here is «what has not ended yet», not «what is
        | running today».
        */
        /*
        | ⛔ THE PLATFORM'S TODAY, NOT UTC'S. Approved between midnight and 03:00
        | in Doha, `CarbonImmutable::today()` answered yesterday — the first paid
        | day had already ended before the student could open anything.
        */
        $today = $this->days->today();

        $runningEnd = Subscription::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $order->user_id)
            ->whereIn('plan_id', $this->lineage->of($plan))
            ->where('status', SubscriptionStatus::Active->value)
            ->where('effective_ends_on', '>=', $today->toDateString())
            ->max('effective_ends_on');

        $starts = $runningEnd === null
            ? $today
            : CarbonImmutable::parse((string) $runningEnd)->addDay();

        /*
        | ⛔ `ends_on` IS INCLUSIVE — THE LAST DAY THAT STILL OPENS — SO A WINDOW
        | OF N DAYS ENDS ON `starts + N − 1`. It was `addDays($window)`: `liveOn()`
        | admits `effective_ends_on >= today` and the sweep expires at `< today`,
        | so a 30-day plan bought on the 15th of October ran to the 14th of
        | November — 31 days — and every renewal chained onto it gained one more.
        | A freeze period is dated the same way (`starts_on + days − 1`), so the
        | two date columns in this product now mean the same thing.
        */
        $ends = $starts->addDays($window - 1);

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
     * ⚠️ `expires_at` IS STAMPED ONLY ON THIS SUBSCRIPTION'S OWN ROW. A student
     * who already bought this course outright gets their existing, open-ended
     * enrolment back from `EnrollStudent` — and stamping an expiry on it would
     * revoke permanent access they paid for, a month later, silently.
     */
    /**
     * Enrol the buyer in everything this plan covers.
     *
     * ⛔ THE END DATE AND THE SOURCE ARE BOTH ARGUMENTS NOW (٠٣٦ · T067), AND
     * NEITHER IS COSMETIC. A plan sold by the hour has no window at all, so
     * `expires_at` is simply not written — an invented date is access that
     * disappears on a day nobody agreed to, while the credits are still there.
     *
     * ⛔ AND THE SOURCE MUST NOT BE `subscription` FOR THE HOURS SHAPE.
     * {@see SubscriptionAccess::close()} closes
     * every enrolment matching `(order_id, source = subscription)` when a
     * subscription expires or is cancelled — so an hours enrolment wearing that
     * source is a row the subscription sweep can reach with **no subscription
     * behind it**, closing access that nothing was ever going to re-open.
     */
    private function openAccess(Order $order, Plan $plan, ?CarbonImmutable $expiresAt, string $source): void
    {
        foreach ($this->coveredCourses($plan) as $course) {
            $enrollment = $this->workspace->forWorkspace(
                (int) $course->workspace_id,
                fn () => $this->enroll->handle(
                    course: $course,
                    student: $order->user,
                    source: $source,
                    orderId: (int) $order->getKey(),
                ),
            );

            // The subscription's own row — new, or handed over by `EnrollStudent`
            // from a lapsed or earlier subscription. Never a purchased one.
            if ($expiresAt !== null
                && $enrollment->source === 'subscription'
                && (int) $enrollment->order_id === (int) $order->getKey()) {
                $enrollment->forceFill(['expires_at' => $expiresAt])->save();
            }
        }
    }

    /**
     * @return EloquentCollection<int, Course>
     */
    /*
    | ⛔ DELEGATED, AND THE THIRD COVERAGE IS WHY. Written here, the `when()` above
    | asked «is this the Course case» — false for a group plan — so the buyer of
    | one group's term was enrolled in EVERY PUBLISHED COURSE the teacher has.
    | Silently, at approval, after the money.
    */
    private function coveredCourses(Plan $plan): EloquentCollection
    {
        return $this->covered->coveredCourses($plan);
    }
}
