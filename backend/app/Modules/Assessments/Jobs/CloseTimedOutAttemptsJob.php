<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Jobs;

use App\Modules\Assessments\Actions\CloseTimedOutAttempts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Every minute: submit the timed sittings whose clock ran out (owner decision
 * 2026-10-29). See {@see CloseTimedOutAttempts}.
 *
 * ⚠️ `WithoutOverlapping` AS JOB MIDDLEWARE, with `expireAfter()` beneath it —
 * `Schedule::job()->withoutOverlapping()` guards only the dispatch
 * (docs/gotchas/deploy-ops.md). An overlap would be harmless anyway (the
 * grading claim refuses the second marker), so this only saves the work.
 */
class CloseTimedOutAttemptsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('exam-timer-close'))->expireAfter(120)];
    }

    public function handle(CloseTimedOutAttempts $action): void
    {
        $action->handle();
    }
}
