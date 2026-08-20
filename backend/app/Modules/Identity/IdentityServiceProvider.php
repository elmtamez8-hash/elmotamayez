<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Listeners\ActivateOnProcessingConsent;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Policies\AuthSessionPolicy;
use App\Modules\Identity\Support\EloquentGuardianDirectory;
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
    }
}
