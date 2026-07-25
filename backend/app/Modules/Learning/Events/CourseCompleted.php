<?php

declare(strict_types=1);

namespace App\Modules\Learning\Events;

use App\Modules\Learning\Models\Enrollment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CourseCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Enrollment $enrollment,
    ) {}
}
