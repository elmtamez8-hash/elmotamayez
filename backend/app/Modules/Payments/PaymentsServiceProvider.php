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
use App\Modules\Payments\Support\EloquentAccountStanding;
use App\Modules\Payments\Support\EloquentConsentDirectory;
use App\Modules\Payments\Support\PaymentsPersonalData;
use App\Modules\Payments\Support\SubscriptionEligibility;
use App\Shared\Contracts\AccountStanding;
use App\Shared\Contracts\ConsentDirectory;
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
        | bind(), and neither of the other two lifetimes. NOT `scoped()`: this is
        | asked once per seat-claim job and once per scheduled session, not the
        | dozens-per-page shape `AssistantScopeDirectory` and `Flags` memoise for.
        | NOT `singleton()`: a worker's container outlives the job, so a memo here
        | would serve a subscriber list that expired hours ago — and the whole
        | point of the moment parameter is that this answer moves.
        */
        $this->app->bind(SubscriptionDirectory::class, SubscriptionEligibility::class);
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
