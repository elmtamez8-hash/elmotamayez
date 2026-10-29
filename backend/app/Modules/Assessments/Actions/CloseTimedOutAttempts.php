<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Models\Attempt;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Submit, on the student's behalf, every official sitting whose time ran out —
 * marked with what they saved (owner decision 2026-10-29).
 *
 * ⚠️ THE TIME TEST IS IN PHP, NOT SQL. The deadline is `started_at` plus a
 * per-row duration, and date arithmetic on a column is spelled differently by
 * SQLite and MySQL — the suite would be green on a form production rejects
 * (docs/gotchas/database.md). SQL narrows to open timed sittings started over a
 * minute ago, which at any instant is the few students mid-exam.
 *
 * ⚠️ ONE FAILURE NEVER STOPS THE REST, and a lost race is not a failure:
 * `GradeAttempt`'s claim refuses a paper the student's own «سلّم» (or another
 * run) took first, and that DomainException means the work is done.
 */
class CloseTimedOutAttempts extends Action
{
    public function __construct(private readonly GradeAttempt $grade) {}

    /** @return int how many sittings this run closed */
    public function handle(): int
    {
        $closed = 0;

        Attempt::query()->withoutWorkspaceScope()
            ->where('status', Attempt::STATUS_IN_PROGRESS)
            ->where('is_practice', false)
            ->where('clock_enforced', true)
            ->whereNotNull('exam_id')
            ->where('duration_minutes', '>', 0)
            ->where('started_at', '<=', now()->subMinute())
            ->chunkById(200, function ($attempts) use (&$closed): void {
                foreach ($attempts as $attempt) {
                    if (! $attempt->isClosedByClock()) {
                        continue;
                    }

                    try {
                        $this->grade->handle($attempt, []);
                        $closed++;
                    } catch (DomainException) {
                        // Submitted by the student, or by an overlapping run.
                    } catch (Throwable $e) {
                        // The class alone: a message here can carry SQL and
                        // answers (docs/gotchas/http-and-security.md).
                        Log::warning('assessments.timer.close_failed', [
                            'attempt_id' => $attempt->getKey(),
                            'exception' => $e::class,
                        ]);
                    }
                }
            });

        return $closed;
    }
}
