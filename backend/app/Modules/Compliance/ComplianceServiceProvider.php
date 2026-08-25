<?php

declare(strict_types=1);

namespace App\Modules\Compliance;

use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Compliance\Policies\DataRequestPolicy;
use App\Modules\Compliance\Policies\LegalHoldPolicy;
use App\Modules\Compliance\Support\EloquentTeacherOffboardingDirectory;
use App\Modules\Compliance\Support\PersonalDataRegistry;
use App\Shared\Contracts\TeacherOffboardingDirectory;
use App\Shared\Modules\Module;
use App\Shared\Modules\ModulesServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Spec 013 — the compliance module.
 *
 * Auto-discovered by {@see ModulesServiceProvider}; never register it by hand in
 * bootstrap/providers.php.
 *
 * ⚠️ THIS MODULE NAMES NO OTHER MODULE'S TABLE, and that is the whole design. The
 * requirement crosses every module that holds a personal column, and the obvious
 * implementation — one Action that knows thirteen schemas — is exactly what
 * Constitution III forbids. What it calls instead is a contract every module
 * implements and registers with one tagged line, the same shape as 003's
 * `notification.channels`.
 *
 * ⚠️ AND IT DOES NOT OWN THE ACCOUNT LIFECYCLE. `ActivateStudentAccount` lives in
 * `Identity`: an Action here writing `users.status` is precisely the violation the
 * contract exists to avoid. Compliance asks `ConsentDirectory` and fires an event;
 * Identity writes.
 */
class ComplianceServiceProvider extends Module
{
    protected string $name = 'Compliance';

    public function register(): void
    {
        parent::register();

        /*
        | The registry is a singleton because it resolves the whole tag — thirteen
        | container bindings — and both the export walk and the nightly sweep ask
        | for it repeatedly inside one process.
        */
        $this->app->singleton(PersonalDataRegistry::class);

        /*
        | ⚠️ `scoped()`, for the two opposite reasons `AssistantScopeDirectory`
        | writes down: not `bind()`, because `ConversationPolicy::post()` asks it
        | on every message and once per row of a conversation list; and not
        | `singleton()`, because a worker's container outlives the job, so an exit
        | completed at noon would keep answering `false` until the worker restarted.
        */
        $this->app->scoped(TeacherOffboardingDirectory::class, EloquentTeacherOffboardingDirectory::class);
    }

    public function boot(): void
    {
        parent::boot();

        /*
        | ⚠️ REGISTERED EXPLICITLY, NEVER LEFT TO THE GUESSER. Laravel's policy
        | guesser fails OPEN — no policy found means "no policy applies" — and it
        | fails open exactly when a directory layout does not match its assumption.
        | A deny-only test passes just as happily against a missing registration as
        | against a working one, which is how `taxonomy.manage` shipped guarding
        | nothing.
        */
        Gate::policy(DataRequest::class, DataRequestPolicy::class);
        Gate::policy(LegalHold::class, LegalHoldPolicy::class);
    }
}
