<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Events;

use App\Modules\Assessments\Models\Attempt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExamPassed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Attempt $attempt,
    ) {}
}
