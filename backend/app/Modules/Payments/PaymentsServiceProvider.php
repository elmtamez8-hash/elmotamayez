<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\LiveSessions\Events\FreezePeriodChanged;
use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Events\AccessRestored;
use App\Modules\Payments\Events\AccessWithheld;
use App\Modules\Payments\Events\BalanceThresholdCrossed;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentReversed;
use App\Modules\Payments\Listeners\ActivateSubscription;
use App\Modules\Payments\Listeners\ChargeSeatsOnDelivery;
use App\Modules\Payments\Listeners\CreateEnrollmentFromOrder;
use App\Modules\Payments\Listeners\CreditPurchaseOnApproval;
use App\Modules\Payments\Listeners\NotifyAccessChange;
use App\Modules\Payments\Listeners\NotifyBalanceThreshold;
use App\Modules\Payments\Listeners\NotifyPaymentOutcome;
use App\Modules\Payments\Listeners\RecomputeSubscriptionEnds;
use App\Modules\Payments\Listeners\ReevaluateOnReversal;
use App\Modules\Payments\Listeners\StampCourseDelivery;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\ExamModeWindow;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\StudentCreditAccount;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Policies\CouponPolicy;
use App\Modules\Payments\Policies\CreditBalancePolicy;
use App\Modules\Payments\Policies\CreditPackagePolicy;
use App\Modules\Payments\Policies\CreditPurchasePolicy;
use App\Modules\Payments\Policies\CreditTransactionPolicy;
use App\Modules\Payments\Policies\ExamModeWindowPolicy;
use App\Modules\Payments\Policies\PaymentTransactionPolicy;
use App\Modules\Payments\Policies\PlanPolicy;
use App\Modules\Payments\Policies\StudentCreditAccountPolicy;
use App\Modules\Payments\Policies\SubscriptionPolicy;
use App\Modules\Payments\Policies\TermsConsentPolicy;
use App\Modules\Payments\Providers\ManualTransferProvider;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Payments\Support\CohortPlanReach;
use App\Modules\Payments\Support\EloquentAccountStanding;
use App\Modules\Payments\Support\EloquentConsentDirectory;
use App\Modules\Payments\Support\EloquentOutstandingCreditsDirectory;
use App\Modules\Payments\Support\EloquentSessionContentAccess;
use App\Modules\Payments\Support\EloquentSessionCreditHolds;
use App\Modules\Payments\Support\EloquentSessionSeatCharges;
use App\Modules\Payments\Support\PaymentsPersonalData;
use App\Modules\Payments\Support\SubscriptionEligibility;
use App\Shared\Contracts\AccountStanding;
use App\Shared\Contracts\CohortPricingReasonDirectory;
use App\Shared\Contracts\ConsentDirectory;
use App\Shared\Contracts\OutstandingCreditsDirectory;
use App\Shared\Contracts\SellableCohortDirectory;
use App\Shared\Contracts\SessionContentAccess;
use App\Shared\Contracts\SessionCreditHolds;
use App\Shared\Contracts\SessionSeatCharges;
use App\Shared\Contracts\SubscriptionDirectory;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class PaymentsServiceProvider extends Module
{
    protected string $name = 'Payments';

    public function register(): void
    {
        parent::register();

        /*
        | Spec 013 — this module's half of the data-rights contract.
        |
        | ⚠️ ONE TAGGED LINE, and `Compliance` names no table of ours. It resolves
        | the tag and walks whatever registered itself — the same shape as 003's
        | `notification.channels`, and the reason a requirement crossing thirteen
        | schemas does not violate Constitution III.
        */
        $this->app->tag([PaymentsPersonalData::class], 'compliance.personal_data');

        // Bind the manual provider as the default implementation.
        $this->app->bind(PaymentProviderInterface::class, ManualTransferProvider::class);

        // ⚠️ ONE LINE PER PROVIDER, and that is the acceptance criterion NFR-003
        // states — ProviderExtensibilityTest registers a second implementation
        // and fails the build if anything under Actions/ or Models/ had to
        // change for it to work. The tag is also the type check: a class that
        // does not implement the interface fails analysis, not production.
        $this->app->tag([ManualTransferProvider::class], 'payment.providers');

        $this->app->singleton(
            PaymentProviderRegistry::class,
            fn ($app) => new PaymentProviderRegistry($app->tagged('payment.providers')),
        );

        // Payments owns the balance, so Payments answers "is this student
        // withheld" — and LiveSessions and Media ask through the interface
        // without learning that `credit_balances` exists. Same shape as
        // Settlement's ApprovedRateDirectory and Identity's GuardianDirectory.
        //
        // bind(), not singleton(): the reader resolves a mode and an exam window
        // per call, and a memo held across a queued job would keep answering
        // about a window that closed while the worker was alive.
        $this->app->bind(AccountStanding::class, EloquentAccountStanding::class);

        /*
        | Spec 013 — the consent record, reached from outside this module.
        |
        | ⚠️ BOUND TO THE ADAPTER, NEVER TO `ConsentRegistry`. The registry is a
        | reader with no `record()`; the only writer is the Action. Binding the
        | contract straight to it would satisfy four methods and leave the fifth
        | with nothing behind it.
        */
        $this->app->bind(ConsentDirectory::class, EloquentConsentDirectory::class);

        /*
        | Spec 027 — what the subscription layer answers to LiveSessions and Learning.
        |
        | bind(), and neither of the other two lifetimes.
        |
        | ⚠️ THE OLD REASON GIVEN HERE WAS MEASURED AND FOUND WRONG, AND THE
        | ANSWER IS STILL `bind()`. It said «asked once per seat-claim job and
        | once per scheduled session, not the dozens-per-page shape
        | `AssistantScopeDirectory` and `Flags` memoise for» — but `POST
        | /class-sessions/{s}/book` reads `subscriptions` TWICE (measured
        | 2026-09-16): `EloquentAccountStanding::refusalFor()` asks
        | `coversCourse()` at the top of its walk, and
        | `EloquentSessionCreditHolds::place()` asks the same one spelling again
        | before it freezes a credit. Both askers are right to ask.
        |
        | ⛔ A `scoped()` MEMO WAS BUILT FOR THAT SECOND READ AND WAS REVERTED,
        | and the reason generalises past this line. Its invalidation hung on
        | `saved`/`deleted` model events — and a BULK `update()` retrieves no
        | models and fires none, which is the `LedgerEntry` rule reached from a
        | new direction. The sibling memo on `EloquentEnrollmentDirectory`, built
        | in the same change, was caught by `ArchiveAfterEnrollmentEndsTest`
        | doing exactly that: a student whose enrolment had been ENDED kept
        | posting into the teacher's chat, 201 where the test demands 403. The
        | coverage memo has the same blind spot and passed only because no test
        | bulk-writes `subscriptions` — untested is not safe, and a stale
        | «covered» fails OPEN on money: a seat taken with no credit frozen
        | against it. Sealing it properly needs a listener on every SQL statement
        | in the application, to save ONE query on a path nobody calls hot.
        |
        | NOT `singleton()`: a worker's container outlives the job, so a memo here
        | would serve a subscriber list that expired hours ago — and the whole
        | point of the moment parameter is that this answer moves.
        */
        $this->app->bind(SubscriptionDirectory::class, SubscriptionEligibility::class);

        /*
        | ٠٣٦ — الجسرُ الثاني: «أيُّ هذه المجموعاتِ يصلُها ثمنٌ نافذ؟» وسببُ الغياب.
        |
        | TWO CONTRACTS AND ONE CLASS BEHIND THEM, deliberately. The listing
        | answer and the teacher's reason are the same three reads; two
        | implementations would be two spellings of the overrule rule, and the
        | day somebody changed one the screen and the door would disagree — which
        | is the whole defect this bridge exists to prevent. They stay two
        | INTERFACES because `Learning` asks the first one from a public page and
        | the second only from the teacher's, and a single interface carrying
        | both is one forgotten branch away from putting a plan's pricing state
        | in front of a guest.
        |
        | `bind()`, the lifetime {@see SubscriptionDirectory} above carries and
        | for the same two reasons. NOT `scoped()`: this is asked once per screen
        | — a course's group list, a teacher's groups tab — not the dozens of
        | times per page that `AssistantScopeDirectory` and `Flags` memoise for,
        | and a memo would have the `LedgerEntry` blind spot the paragraph above
        | records, since a bulk `update()` on `plans` fires no model event. NOT
        | `singleton()`: a worker's container outlives the job, and the one thing
        | this answer must do is change the minute an officer prices a plan.
        */
        $this->app->bind(SellableCohortDirectory::class, CohortPlanReach::class);
        $this->app->bind(CohortPricingReasonDirectory::class, CohortPlanReach::class);

        /*
        | ٠٣٥ — عقدُ الكتابة: الحجزُ يُجمِّدُ الرصيدَ ولا يخصمُه.
        |
        | `scoped()`, for the two OPPOSITE reasons written above
        | `AssistantScopeDirectory` and `Flags`: NOT `bind()`, because the
        | release path walks several sessions in one request when a teacher
        | cancels a day and a freeze period suspends a week — rebuilding the
        | graph for each is the shape those two memoise away. NOT `singleton()`,
        | because a worker's container outlives the job and this reads the
        | billing mode and the ceiling, which an operator moves from the panel
        | while the worker is running.
        */
        $this->app->scoped(SessionCreditHolds::class, EloquentSessionCreditHolds::class);

        /*
        | ٠٣٥ — عقدُ القراءة: السؤالُ الواحدُ الذي تسألُه الأبوابُ الستّة.
        |
        | `scoped()` for the same two opposite reasons: six gates ask it on one
        | curriculum read, so `bind()` rebuilds the graph six times per page —
        | and `singleton()` outlives a queued job, which would serve a verdict
        | frozen before the session it is about even closed.
        */
        $this->app->scoped(SessionContentAccess::class, EloquentSessionContentAccess::class);

        /*
        | 035 — the exceptional door: an excuse accepted after the charge.
        |
        | `bind()` rather than `scoped()`: this is asked at most once per
        | register correction, which is the rarest write in the product — not the
        | dozens-per-page shape the two memoised bindings above exist for.
        */
        $this->app->bind(SessionSeatCharges::class, EloquentSessionSeatCharges::class);

        /*
        | 006 · T097 — the counter beside the rate decision, asked not imported.
        |
        | `bind()`: read ONCE per screen render (one table, one column), which is
        | neither the dozens-per-page shape `scoped()` exists for nor anything a
        | worker holds. The reader is the settlement side, which may not name a
        | Payments type in any form — `ContextIsolationTest` scans every file
        | under `Modules/Settlement/` for `use App\Modules\Payments` and for a
        | quoted billing table name, and forbids both wherever they appear.
        */
        $this->app->bind(OutstandingCreditsDirectory::class, EloquentOutstandingCreditsDirectory::class);
    }

    public function boot(): void
    {
        parent::boot();

        // One event, two listeners, split on `orders.kind`. A course order enrols;
        // a credit order mints credits. Each ignores the other's kind, and
        // without that split buying credits would hand over the whole course.
        Event::listen(PaymentApproved::class, CreateEnrollmentFromOrder::class);
        Event::listen(PaymentApproved::class, CreditPurchaseOnApproval::class);

        /*
        | The instant path (007) — and WITHOUT THESE TWO LINES THE WHOLE OF US1
        | ENDS IN A CAPTURED TRANSACTION AND A BALANCE THAT NEVER MOVED.
        |
        | The two listeners are the ones already bound to PaymentApproved, on
        | purpose: a gateway capture and a hand-approved receipt are the same
        | business fact — this order is paid for — and a second pair of listeners
        | would be a second definition of what being paid for means. They accept
        | both events through CarriesPaidOrder; before that contract they were
        | typed on PaymentApproved and this binding would have thrown a TypeError
        | on the first successful payment.
        |
        | The PaymentApproved bindings above stay exactly as they are: the manual
        | path is not being replaced.
        */
        Event::listen(PaymentCaptured::class, CreateEnrollmentFromOrder::class);
        Event::listen(PaymentCaptured::class, CreditPurchaseOnApproval::class);

        Event::listen(PaymentCaptured::class, [NotifyPaymentOutcome::class, 'handleCaptured']);
        Event::listen(PaymentFailed::class, [NotifyPaymentOutcome::class, 'handleFailed']);

        /*
        | A reversal re-opens the question the payment had closed. Bound here and
        | not only created: an unbound listener is the same defect as an unbound
        | PaymentCaptured — code that exists, reads correctly, and never runs.
        */
        Event::listen(PaymentReversed::class, ReevaluateOnReversal::class);

        /*
        | Spec 011 · US4 — the subscription starts when the money is witnessed.
        |
        | ⚠️ BOTH DOORS, for the same reason the two above are bound twice: a
        | hand-approved receipt and a gateway capture are one business fact, and
        | binding one of them leaves every subscription bought through the other
        | paid for and never activated. `unique(order_id)` is what makes a
        | redelivery of either harmless.
        */
        Event::listen(PaymentApproved::class, ActivateSubscription::class);
        Event::listen(PaymentCaptured::class, ActivateSubscription::class);

        /*
        | Spec 011 · US4 · FR-031 — a freeze moves what it covers.
        |
        | The event is announced by `FreezePeriod::booted()`, so all three writes
        | (declare, edit, LIFT) arrive here without LiveSessions naming a
        | subscription anywhere. The fourth recompute moment — a subscription
        | activated inside a freeze already running — belongs to
        | `ActivateSubscription`, because no freeze row changed at that moment.
        */
        Event::listen(FreezePeriodChanged::class, RecomputeSubscriptionEnds::class);

        // The stop-selling signal (FR-021ط). SessionDelivered is the ONLY bridge
        // billing may use to learn that a course is still being taught — the
        // settlement tables that also know it are across a boundary
        // ContextIsolationTest fails the build over.
        Event::listen(SessionDelivered::class, StampCourseDelivery::class);

        // The charge. Same event, because delivery is the ONLY thing that turns
        // a seat into money — see the listener for the two neighbouring
        // attendance events left alone and why the 005 code forces that choice.
        Event::listen(SessionDelivered::class, ChargeSeatsOnDelivery::class);

        /*
        | The collection ladder (FR-030 … FR-034).
        |
        | Three events, and the split carries the design: a THRESHOLD warns about
        | where the balance is heading, and being WITHHELD states what the
        | account can do right now. One listener serves both access events
        | because they are a single fact stated twice — the predicate flipped —
        | and two files would end with the lift reaching fewer people than the
        | block did.
        |
        | None of them fires twice for one crossing. The tier is claimed with a
        | conditional UPDATE inside the transaction that moved the balance, so an
        | event that reaches a listener is already known to be new; a second
        | check here would be a second definition of "already announced".
        */
        Event::listen(BalanceThresholdCrossed::class, NotifyBalanceThreshold::class);
        Event::listen(AccessWithheld::class, [NotifyAccessChange::class, 'handleWithheld']);
        Event::listen(AccessRestored::class, [NotifyAccessChange::class, 'handleRestored']);

        // Registered explicitly, like Identity's, LiveSessions' and
        // Settlement's. Laravel's guesser would find them anyway — it walks the
        // namespace up and lands on Modules\Payments\Policies — but a guessed
        // binding is invisible: a policy renamed or moved stops applying and
        // every ability it denied starts passing, with nothing to grep for. This
        // list is the mapping, in one readable place.
        Gate::policy(StudentCreditAccount::class, StudentCreditAccountPolicy::class);
        Gate::policy(CreditBalance::class, CreditBalancePolicy::class);
        Gate::policy(CreditTransaction::class, CreditTransactionPolicy::class);
        Gate::policy(CreditPurchase::class, CreditPurchasePolicy::class);
        Gate::policy(CreditPackage::class, CreditPackagePolicy::class);
        Gate::policy(Coupon::class, CouponPolicy::class);
        Gate::policy(TermsConsent::class, TermsConsentPolicy::class);
        // ⚠️ Ownership of the order and nothing else — see the policy. Without
        // it a teacher reads the total a named student paid, which FR-033
        // forbids, and BelongsToWorkspace lets them through the only automatic
        // filter there is.
        Gate::policy(PaymentTransaction::class, PaymentTransactionPolicy::class);
        Gate::policy(ExamModeWindow::class, ExamModeWindowPolicy::class);
        // Spec 011 · US4. Two permissions on one row — the teacher writes the
        // duration and the coverage, the platform writes the price — and the
        // guesser fails OPEN, so an unregistered policy denies nothing.
        Gate::policy(Plan::class, PlanPolicy::class);
        Gate::policy(Subscription::class, SubscriptionPolicy::class);
    }
}
