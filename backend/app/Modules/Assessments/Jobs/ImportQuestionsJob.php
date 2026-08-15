<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Jobs;

use App\Modules\Assessments\Actions\ImportQuestions;
use App\Modules\Assessments\Enums\ImportStatus;
use App\Modules\Assessments\Events\QuestionImported;
use App\Modules\Assessments\Models\QuestionImport;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one upload, off the request.
 *
 * A thousand rows is a thousand inserts; doing it inline would hold the teacher's
 * browser open past every timeout there is (FR-008).
 */
class ImportQuestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $importId,
    ) {}

    /**
     * ⚠️ ONE IMPORT PER WORKSPACE AT A TIME, AND THIS IS THE DUPLICATE GUARD.
     *
     * `DuplicatePolicy::Skip` is a lookup followed by an insert, because the
     * unique index it was designed around could not be created on live data.
     * This lock is what makes that lookup safe: two uploads of the same file
     * cannot be inside it together. Remove it and "skip" silently becomes
     * "sometimes skip".
     *
     * `dontRelease()` rather than a retry: a second upload waiting on the first
     * would sit in the queue re-attempting, and the teacher who uploaded twice by
     * accident wants the second one to fail loudly, not to run an hour later.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        $workspaceId = QuestionImport::query()
            ->withoutWorkspaceScope()
            ->whereKey($this->importId)
            ->value('workspace_id');

        return [(new WithoutOverlapping('question-import:'.$workspaceId))->dontRelease()];
    }

    public function handle(WorkspaceContext $context, ImportQuestions $action): void
    {
        $import = QuestionImport::query()->withoutWorkspaceScope()->find($this->importId);

        if ($import === null) {
            return;
        }

        /*
         | ⚠️ THE CLAIM COMES FIRST, AND A LOST CLAIM IS A SUCCESS.
         |
         | Horizon retries a job that timed out mid-file. That retry finds the row
         | no longer `queued`, loses the conditional UPDATE, and returns having
         | done nothing — which is the correct outcome. A retry that ran again
         | would re-insert every question the first attempt already committed, and
         | the teacher would find their bank doubled with no error anywhere.
         |
         | There is deliberately no resume. Continuing from a byte offset written
         | by a process that died is a harder promise than re-reading the file.
         */
        if (! $import->claim()) {
            return;
        }

        // ⚠️ forWorkspace, NEVER WorkspaceContext::set(). The context is an
        // application-wide singleton that caches its resolution, so a set() here
        // leaks this teacher's workspace into whatever the same worker handles
        // next.
        $context->forWorkspace((int) $import->workspace_id, function () use ($import, $action): void {
            // Fired for BOTH outcomes. The teacher closed the tab after the 202,
            // so this notification is their only route back — and an import that
            // failed is the one they most need to hear about.
            event(new QuestionImported($action->handle($import)));
        });
    }

    /**
     * The job died for a reason the Action never saw — a timeout, an out-of-memory,
     * a worker restart. Without this the import sits at `running` for ever and the
     * teacher's screen spins on a file nobody is reading.
     */
    public function failed(?\Throwable $exception): void
    {
        QuestionImport::query()
            ->withoutWorkspaceScope()
            ->whereKey($this->importId)
            ->where('status', ImportStatus::Running->value)
            ->update([
                'status' => ImportStatus::Failed->value,
                'failure_reason' => $exception?->getMessage() ?? 'توقّفت المعالجة.',
                'finished_at' => now(),
            ]);
    }
}
