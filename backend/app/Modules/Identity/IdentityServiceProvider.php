<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Compliance\Events\TeacherOffboardingCompleted;
use App\Modules\Identity\Listeners\ActivateOnProcessingConsent;
use App\Modules\Identity\Listeners\CompleteReferral;
use App\Modules\Identity\Listeners\ReverseReferralAward;
use App\Modules\Identity\Listeners\RevokeTeacherSessions;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Policies\AuthSessionPolicy;
use App\Modules\Identity\Support\EloquentGuardianDirectory;
use App\Modules\Identity\Support\IdentityPersonalData;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Payments\Events\PaymentReversed;
use App\Modules\Payments\Events\ProcessingConsentGranted;
use App\Modules\Payments\Events\RefundIssued;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class IdentityServiceProvider extends Module
{
    protected string $name = 'Identity';

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
        $this->app->tag([IdentityPersonalData::class], 'compliance.personal_data');

        // Identity owns the relation; Notifications asks through the interface.
        // Reaching into another module's models directly is what Constitution III
        // forbids, and this binding is the sanctioned way around it.
        $this->app->bind(GuardianDirectory::class, EloquentGuardianDirectory::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(AuthSession::class, AuthSessionPolicy::class);

        /*
        | Spec 013 — a guardian consents in `Payments`, an account opens here.
        |
        | Wired with `Event::listen` in the SUBSCRIBING module, which is the only
        | shape this codebase has (there is no EventServiceProvider). The reverse —
        | `RecordTermsConsent` calling an Identity Action — would be a write to
        | another module's aggregate from inside a third one's transaction.
        */
        Event::listen(ProcessingConsentGranted::class, ActivateOnProcessingConsent::class);

        /*
        | Spec 013 · FR-037 — a departed teacher is signed out everywhere. At
        | COMPLETION only: the notice period exists so they can finish the lessons
        | their students were promised, and revoking their tokens the moment they
        | ask to leave locks them out of exactly that.
        */
        Event::listen(TeacherOffboardingCompleted::class, RevokeTeacherSessions::class);

        /*
        | Spec 011 · US3 — a referral pays only when the invited person actually
        | subscribes (FR-019 · SC-006).
        |
        | ⚠️ BOTH DOORS ON A PAYMENT, exactly as the credits mint is bound. A
        | manual transfer an operator approves and a gateway capture are two
        | events for one fact, and binding one leaves every referral completed
        | through the other silently pending for ever.
        |
        | The `kind` filter lives INSIDE the listener rather than in a choice of
        | bindings: it is a business rule about what «subscription» means, and a
        | rule expressed by which events you happen to subscribe to is a rule
        | nobody can find when they go looking for it.
        */
        Event::listen(PaymentApproved::class, CompleteReferral::class);
        Event::listen(PaymentCaptured::class, CompleteReferral::class);

        // And the two ways money goes back (FR-021 · SC-007). The design named
        // the shape of the reversal without naming a trigger, which would have
        // made SC-007 a criterion with no entrance.
        Event::listen(PaymentReversed::class, ReverseReferralAward::class);
        Event::listen(RefundIssued::class, ReverseReferralAward::class);
    }
}
