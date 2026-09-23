<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Jobs;

use App\Modules\Settlement\Actions\CloseSettlementPeriod;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Settlement\Support\SettlementWindow;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Traits\RunsAlone;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opens a window for work that has none, and closes one whose days have run out.
 *
 * Two jobs' worth of intent in one sweep, because they are the same walk over
 * the same teachers and splitting them would mean a teacher could have a window
 * opened by one run and closed by another with different arithmetic in between.
 *
 * The period row exists so that a total can be frozen onto it. Nothing needs one
 * before then — `BuildTeacherStatement` defines the open window as "every unit no
 * close has claimed", which is true whether or not a row names those days.
 */
class CloseDueSettlementPeriodsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsAlone, SerializesModels;

    public function handle(
        WorkspaceContext $context,
        CloseSettlementPeriod $close,
        SettlementWindow $window,
    ): void {
        // Teachers with unclaimed work, read across every workspace at once —
        // one query rather than one per tenant.
        $teachers = TeachingUnit::query()
            ->withoutWorkspaceScope()
            ->whereNull('settlement_period_id')
            ->where('status', TeachingUnitStatus::Accrued->value)
            // A stable walk order, so the teacher a failure stops at — and every
            // teacher after it — is the same on every engine and every night.
            ->orderBy('workspace_id')
            ->orderBy('teacher_profile_id')
            ->get(['workspace_id', 'teacher_profile_id'])
            ->unique(fn (TeachingUnit $unit): string => $unit->workspace_id.':'.$unit->teacher_profile_id);

        $failure = null;

        foreach ($teachers as $row) {
            $workspace = Workspace::query()->find($row->workspace_id);

            if ($workspace === null) {
                continue;
            }

            // forWorkspace, never set(): WorkspaceContext is an application-wide
            // singleton that caches its resolution, so a direct set here leaks
            // this workspace into whatever the same worker handles next.
            // TrustScoreJobIsolationTest's sibling rule fails the build over it.
            try {
                $context->forWorkspace($workspace, function () use ($row, $close, $window): void {
                    $this->settle((int) $row->teacher_profile_id, $close, $window);
                });
            } catch (Throwable $e) {
                /*
                | ⚠️ PER TEACHER, BECAUSE ONE BAD ROW USED TO END THE NIGHT FOR
                | EVERYONE. The walk is one loop over every teacher on the platform;
                | a throw on the first stopped every close behind it, and the next
                | run met the same row first again — nobody's window closed, for as
                | long as that row stood. Per teacher rather than per period: a
                | teacher's periods close oldest first, and closing a later window
                | over an earlier one that failed would freeze a total out of order.
                |
                | The CLASS only: a QueryException's message carries its bindings,
                | and this line ships to a monitoring vendor. The full message is
                | kept — the first failure is rethrown after the walk, so it lands
                | in `failed_jobs`, in our own database.
                */
                Log::error('settlement.close_due.teacher_failed', [
                    'workspace_id' => (int) $row->workspace_id,
                    'teacher_profile_id' => (int) $row->teacher_profile_id,
                    'exception' => $e::class,
                ]);

                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function settle(int $teacherProfileId, CloseSettlementPeriod $close, SettlementWindow $window): void
    {
        // EVERY open period, oldest first — not just the newest. A backlog is
        // exactly what this sweep exists to clear, and closing only the latest
        // would leave an older window open forever while its units sat unclaimed
        // behind a period nobody looks at.
        $open = SettlementPeriod::query()
            ->where('teacher_profile_id', $teacherProfileId)
            ->where('status', SettlementPeriodStatus::Open->value)
            ->orderBy('starts_on')
            ->get();

        if ($open->isEmpty()) {
            $open = collect([$this->openPeriod($teacherProfileId, $window)]);
        }

        foreach ($open as $period) {
            // Not due yet. Closing a window the teacher is still working in
            // freezes a total over days that have not happened.
            if (CarbonImmutable::parse($period->ends_on->toDateString())->addDay()->isFuture()) {
                continue;
            }

            // No actor. Nobody closed this — the schedule did, and `closed_by` is
            // nullable precisely so that can be said. Standing in the first super
            // admin would put a name on a decision no person made.
            $close->handle($period);
        }
    }

    /**
     * Open the window this teacher's unclaimed work falls in.
     *
     * `create` and catch, never "look then insert" — that is the definition of
     * the race, and two open periods over the same days would each claim half the
     * teacher's units. The unique index on `(teacher_profile_id, starts_on)` is
     * what the loser hits; the window's start is derived from the previous close,
     * so both racers compute the same one.
     */
    private function openPeriod(int $teacherProfileId, SettlementWindow $window): SettlementPeriod
    {
        $lastClosed = $window->lastClosed($teacherProfileId);
        [$startsOn, $endsOn] = $window->bounds($teacherProfileId, null, $lastClosed);

        try {
            return SettlementPeriod::query()->create([
                'teacher_profile_id' => $teacherProfileId,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'status' => SettlementPeriodStatus::Open,
                'currency' => $lastClosed === null
                    ? (string) config('settlement.currency', 'QAR')
                    : (string) $lastClosed->currency,
            ]);
        } catch (QueryException $e) {
            $existing = $window->open($teacherProfileId);

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }
}
