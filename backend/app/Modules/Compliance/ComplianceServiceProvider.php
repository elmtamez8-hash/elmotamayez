<?php

declare(strict_types=1);

namespace App\Modules\Compliance;

use App\Modules\Compliance\Support\PersonalDataRegistry;
use App\Shared\Modules\Module;
use App\Shared\Modules\ModulesServiceProvider;

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
    }
}
