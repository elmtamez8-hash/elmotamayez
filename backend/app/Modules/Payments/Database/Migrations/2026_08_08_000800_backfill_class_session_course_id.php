<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fills class_sessions.course_id where the answer is unambiguous, and leaves the
 * rest alone.
 *
 * The column STAYS nullable, permanently. Historic sessions predate Q-7 and
 * genuinely had no course; inventing one for them would put a guess in the
 * table that later reads as a fact. The rule is enforced at the door instead —
 * ScheduleClassSession and StoreClassSessionRequest both require a course now —
 * so what the phase actually guarantees is "zero SCHEDULABLE sessions without a
 * course", not "zero historic rows".
 *
 * This matters because the first version of the plan promised both a backfill
 * and a NOT NULL constraint, and those two cannot hold together: the constraint
 * migration fails on the first surviving row, after the backfill migration has
 * already committed.
 *
 * Only workspaces holding exactly ONE course are filled. Where a workspace has
 * several, the session could have belonged to any of them, and a heuristic that
 * picks one would be writing an answer nobody can check. The remainder is
 * counted and logged rather than silently skipped.
 *
 * chunkById, never chunk: the predicate `course_id IS NULL` shrinks as rows are
 * fixed, and OFFSET paging under a shrinking predicate skips as many rows per
 * page as the previous page repaired — while reporting success. Same lesson as
 * the uuid backfill in 016.
 *
 * No `UPDATE ... JOIN`: MySQL and SQLite disagree on its syntax.
 */
return new class extends Migration
{
    public function up(): void
    {
        $soleCourseByWorkspace = DB::table('courses')
            ->whereNull('deleted_at')
            ->select('workspace_id', DB::raw('COUNT(*) as course_count'), DB::raw('MIN(id) as course_id'))
            ->groupBy('workspace_id')
            ->having('course_count', '=', 1)
            ->get()
            ->keyBy('workspace_id');

        $filled = 0;

        if ($soleCourseByWorkspace->isNotEmpty()) {
            DB::table('class_sessions')
                ->whereNull('course_id')
                ->select('id', 'workspace_id')
                ->chunkById(500, function ($sessions) use ($soleCourseByWorkspace, &$filled): void {
                    foreach ($sessions as $session) {
                        $match = $soleCourseByWorkspace->get($session->workspace_id);

                        if ($match === null) {
                            continue;
                        }

                        DB::table('class_sessions')
                            ->where('id', $session->id)
                            ->update(['course_id' => $match->course_id]);

                        $filled++;
                    }
                });
        }

        $remaining = DB::table('class_sessions')->whereNull('course_id')->count();

        // Reported, not swallowed. A backfill that leaves rows behind and says
        // nothing is indistinguishable from one that filled everything.
        Log::info('006 backfill: class_sessions.course_id', [
            'filled' => $filled,
            'left_null' => $remaining,
            'note' => 'Rows left null are pre-Q-7 sessions in multi-course workspaces. '
                .'The column stays nullable; presence is enforced in ScheduleClassSession.',
        ]);
    }

    public function down(): void
    {
        // Not reversed. Undoing it would mean deciding which of the filled rows
        // were filled by this migration rather than written by a scheduler
        // afterwards, and that information is not on the row.
    }
};
