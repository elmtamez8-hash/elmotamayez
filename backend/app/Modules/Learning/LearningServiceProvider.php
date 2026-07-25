<?php

declare(strict_types=1);

namespace App\Modules\Learning;

use App\Shared\Modules\Module;

class LearningServiceProvider extends Module
{
    protected string $name = 'Learning';

    public function boot(): void
    {
        parent::boot();
    }
}
