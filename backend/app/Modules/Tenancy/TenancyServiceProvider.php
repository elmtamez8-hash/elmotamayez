<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Events\WorkspaceCreated;
use App\Modules\Tenancy\Listeners\SeedDefaultRoles;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;

class TenancyServiceProvider extends Module
{
    protected string $name = 'Tenancy';

    public function boot(): void
    {
        parent::boot();

        Event::listen(
            WorkspaceCreated::class,
            SeedDefaultRoles::class,
        );
    }
}
