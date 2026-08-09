<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Listeners\ChargeSeatsOnDelivery;
use App\Modules\Payments\Listeners\CreateEnrollmentFromOrder;
use App\Modules\Payments\Listeners\CreditPurchaseOnApproval;
use App\Modules\Payments\Listeners\StampCourseDelivery;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\ExamModeWindow;
use App\Modules\Payments\Models\StudentCreditAccount;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Policies\CreditBalancePolicy;
use App\Modules\Payments\Policies\CreditPackagePolicy;
use App\Modules\Payments\Policies\CreditPurchasePolicy;
use App\Modules\Payments\Policies\CreditTransactionPolicy;
use App\Modules\Payments\Policies\ExamModeWindowPolicy;
use App\Modules\Payments\Policies\StudentCreditAccountPolicy;
use App\Modules\Payments\Policies\TermsConsentPolicy;
use App\Modules\Payments\Providers\ManualTransferProvider;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class PaymentsServiceProvider extends Module
{
    protected string $name = 'Payments';

    public function register(): void
    {
        parent::register();

        // Bind the manual provider as the default implementation.
        $this->app->bind(PaymentProviderInterface::class, ManualTransferProvider::class);
    }

    public function boot(): void
    {
        parent::boot();

        // One event, two listeners, split on `orders.kind`. A course order enrols;
        // a credit order mints credits. Each ignores the other's kind, and
        // without that split buying credits would hand over the whole course.
        Event::listen(PaymentApproved::class, CreateEnrollmentFromOrder::class);
        Event::listen(PaymentApproved::class, CreditPurchaseOnApproval::class);

        // The stop-selling signal (FR-021ط). SessionDelivered is the ONLY bridge
        // billing may use to learn that a course is still being taught — the
        // settlement tables that also know it are across a boundary
        // ContextIsolationTest fails the build over.
        Event::listen(SessionDelivered::class, StampCourseDelivery::class);

        // The charge. Same event, because delivery is the ONLY thing that turns
        // a seat into money — see the listener for the two neighbouring
        // attendance events left alone and why the 005 code forces that choice.
        Event::listen(SessionDelivered::class, ChargeSeatsOnDelivery::class);

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
        Gate::policy(TermsConsent::class, TermsConsentPolicy::class);
        Gate::policy(ExamModeWindow::class, ExamModeWindowPolicy::class);
    }
}
