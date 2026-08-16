<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Jobs;

use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Assessments\Support\ApplyAccommodation;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The deadline passes and the register writes itself (FR-051).
 *
 * ⚠️ NEVER `WorkspaceContext::set()`. It is an application-wide singleton that
 * caches its resolution, so a workspace set inside a job leaks into whatever the
 * same worker handles next — a listener, another job, a queued notification —
 * and the leak is silent. `forWorkspace()` restores what was there. This is the
 * only job in spec 008 that crosses workspaces at all, which makes it the one
 * where the rule matters.
 *
 * ⚠️ `insertOrIgnore` BOOTS NO MODEL, so `HasUuid` never fires. On MySQL the
 * resulting NOT NULL violation is downgraded to a warning and `''` is stored —
 * after which EVERY later submission row on the platform collides with that one
 * on `unique(uuid)`, is read as "already recorded", and is silently skipped. The
 * sweep would then stop after one row while reporting success, for ever. `uuid`
 * and `created_at` are passed EXPLICITLY below for exactly that reason, and the
 * rows are read back afterwards: zero written means either a duplicate or a
 * swallowed failure, and the two must not be confused.
 *
 * ⚠️ AND IT CONSULTS THE ACCOMMODATIONS. A student granted two extra days who is
 * stamped `missed` on the first night is then hidden by US7's unlock gate — and
 * they are the first person the arrangement was built for.
 */
class MarkMissedSubmissionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WorkspaceContext $context, ApplyAccommodation $accommodations): void
    {
        $now = Carbon::now();

        Workspace::query()->orderBy('id')->chunkById(50, function ($workspaces) use ($context, $accommodations, $now): void {
            foreach ($workspaces as $workspace) {
                $context->forWorkspace(
                    $workspace,
                    fn () => $this->sweepWorkspace((int) $workspace->getKey(), $accommodations, $now),
                );
            }
        });
    }

    private function sweepWorkspace(int $workspaceId, ApplyAccommodation $accommodations, Carbon $now): void
    {
        Assignment::query()
            ->published()
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(50, function ($assignments) use ($workspaceId, $accommodations, $now): void {
                foreach ($assignments as $assignment) {
                    $this->sweepAssignment($assignment, $workspaceId, $accommodations, $now);
                }
            });
    }

    private function sweepAssignment(Assignment $assignment, int $workspaceId, ApplyAccommodation $accommodations, Carbon $now): void
    {
        $expected = $this->expectedStudentIds($assignment);

        if ($expected === []) {
            return;
        }

        $existing = Submission::query()
            ->where('assignment_id', $assignment->getKey())
            ->pluck('extension_until', 'student_user_id');

        $rows = [];
        $stamp = $now->toDateTimeString();

        foreach ($expected as $studentId) {
            /*
            | ⚠️ THE STUDENT'S OWN DEADLINE, NOT THE ASSIGNMENT'S. An extension
            | on the row beats everything; otherwise the standing accommodation
            | adds whole days. Sweeping on the raw `due_at` marks precisely the
            | people who were told they had longer.
            */
            $extension = $existing[$studentId] ?? null;

            $deadline = $extension !== null
                ? Carbon::parse((string) $extension)
                : Carbon::parse($assignment->due_at)->addDays($accommodations->extendedDays($workspaceId, $studentId));

            if ($deadline->greaterThan($now)) {
                continue;
            }

            if ($existing->has($studentId)) {
                continue;
            }

            $rows[] = [
                // ⚠️ EXPLICIT, because insertOrIgnore boots no model. See the
                // class docblock: the alternative is one empty uuid poisoning
                // every submission the platform ever records afterwards.
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'assignment_id' => $assignment->getKey(),
                'student_user_id' => $studentId,
                'state' => Submission::STATE_MISSED,
                'submitted_at' => null,
                'late_by_minutes' => 0,
                'late_penalty_applied_pct' => 0,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        if ($rows === []) {
            $this->markLapsedExtensions($assignment, $now);

            return;
        }

        DB::table('submissions')->insertOrIgnore($rows);

        /*
        | ⚠️ READ BACK, ALWAYS. `insertOrIgnore` returns zero for a duplicate and
        | zero for a swallowed failure, and the two are the same number. A sweep
        | that trusted it would report a clean night over a table it never wrote
        | to. A miss here is a bug worth failing loudly on, not a row to shrug at.
        */
        $written = Submission::query()
            ->where('assignment_id', $assignment->getKey())
            ->whereIn('student_user_id', array_column($rows, 'student_user_id'))
            ->count();

        if ($written < count($rows)) {
            throw new \RuntimeException(
                "MarkMissedSubmissionsJob wrote {$written} of ".count($rows)." rows for assignment {$assignment->getKey()}.",
            );
        }

        $this->markLapsedExtensions($assignment, $now);
    }

    /**
     * A row the sweep created for an extension that has since run out.
     *
     * ⚠️ `WHERE submitted_at IS NULL`, NEVER A BLIND UPDATE. The row may hold a
     * hand-in made inside the extension — writing `missed` over it erases work
     * the student did on time, and the teacher never learns it was there.
     */
    private function markLapsedExtensions(Assignment $assignment, Carbon $now): void
    {
        Submission::query()
            ->where('assignment_id', $assignment->getKey())
            ->whereNull('submitted_at')
            ->whereNotNull('extension_until')
            ->where('extension_until', '<=', $now)
            ->where('state', Submission::STATE_PENDING)
            ->update(['state' => Submission::STATE_MISSED]);
    }

    /**
     * Who was supposed to hand this in.
     *
     * The enrolments in the assignment's course; an assignment filed under no
     * course belongs to nobody in particular and is swept for nobody — a
     * deliberate no-op rather than a guess at the whole workspace.
     *
     * @return list<int>
     */
    private function expectedStudentIds(Assignment $assignment): array
    {
        if ($assignment->course_id === null) {
            return [];
        }

        $ids = DB::table('enrollments')
            ->where('workspace_id', $assignment->workspace_id)
            ->where('course_id', $assignment->course_id)
            ->where('status', 'active')
            ->pluck('student_user_id');

        $out = [];

        foreach ($ids as $id) {
            $out[] = (int) $id;
        }

        return $out;
    }
}
