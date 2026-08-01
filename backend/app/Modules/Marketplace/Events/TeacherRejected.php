<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Events;

use App\Modules\Marketplace\Models\TeacherApplication;
use Illuminate\Foundation\Events\Dispatchable;

class TeacherRejected
{
    use Dispatchable;

    public function __construct(
        public readonly TeacherApplication $application,
        public readonly string $reason,
    ) {}
}
