<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Compliance\Events\TeacherOffboardingCompleted;
use App\Modules\Identity\Listeners\ActivateOnProcessingConsent;
use App\Modules\Identity\Listeners\RevokeTeacherSessions;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Policies\AuthSessionPolicy;
use App\Modules\Identity\Support\EloquentGuardianDirectory;
use App\Modules\Identity\Support\IdentityPersonalData;
use App\Modules\Payments\Events\ProcessingConsentGranted;
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
    }
}
