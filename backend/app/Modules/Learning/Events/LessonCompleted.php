<?php

declare(strict_types=1);

namespace App\Modules\Learning\Events;

use App\Modules\Learning\Models\LessonProgress;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LessonCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly LessonProgress $progress,
    ) {}
}
