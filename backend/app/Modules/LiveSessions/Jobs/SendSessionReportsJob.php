<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Modules\LiveSessions\Actions\SendSessionReport;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The post-session report, one row at a time (FR-033 · SC-009).
 *
 * Dispatched with `report_delay_minutes` of delay when the session closes. The
 * delay is what gives a teacher a window to write remarks before the guardian
 * hears anything — but it is a window, not a dependency: the job sends whatever
 * has been written by the time it runs and never waits for more (FR-035).
 *
 * Rows already reported are skipped, so a retried job cannot tell one guardian
 * about the same hour twice. That guard is a column and not a job-level flag
 * because a correction has to be able to reopen exactly one row.
 */
class SendSessionReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $classSessionId,
    ) {}

    public function handle(WorkspaceContext $context, SendSessionReport $send): void
    {
        $session = ClassSession::query()->withoutWorkspaceScope()->find($this->classSessionId);

        if ($session === null) {
            return;
        }

        $context->forWorkspace((int) $session->workspace_id, function () use ($session, $send): void {
            $rows = $session->attendances()
                ->whereNull('report_sent_at')
                ->with('student')
                ->get();

            foreach ($rows as $attendance) {
                // The teacher is a row in their own register (that is how
                // delivery is judged), and nobody reports a teacher's attendance
                // to their guardian.
                if ((int) $attendance->student_user_id === (int) $session->teacherProfile?->user_id) {
                    continue;
                }

                $send->handle($attendance);
            }
        });
    }
}
