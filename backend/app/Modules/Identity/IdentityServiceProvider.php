<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Policies\AuthSessionPolicy;
use App\Modules\Identity\Support\EloquentGuardianDirectory;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Modules\Module;
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
    }
}
