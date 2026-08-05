<?php

declare(strict_types=1);

namespace App\Modules\Learning;

use App\Modules\Learning\Support\EloquentEnrollmentDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Modules\Module;

class LearningServiceProvider extends Module
{
    protected string $name = 'Learning';

    public function register(): void
    {
        parent::register();

        // Learning owns the enrolment; Media asks through the interface rather
        // than reaching into these models, which Constitution III forbids. Same
        // binding shape as Identity's GuardianDirectory.
        $this->app->bind(EnrollmentDirectory::class, EloquentEnrollmentDirectory::class);
    }

    public function boot(): void
    {
        parent::boot();
    }
}
