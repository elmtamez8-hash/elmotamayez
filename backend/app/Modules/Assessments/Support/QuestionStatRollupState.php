<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Jobs\RollUpQuestionStatsJob;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one row {@see RollUpQuestionStatsJob} keeps between runs — see the
 * `create_question_stat_rollup_state_table` migration for why each column exists.
 *
 * Read through the query builder: a single platform-level row with no uuid and
 * no route, so a model would be ceremony.
 */
final class QuestionStatRollupState
{
    private const TABLE = 'question_stat_rollup_state';

    private const ROW = 1;

    /**
     * Where the next run must start from, or null when it has to recompute
     * everything: no previous run, a floor that moved, or a deletion since.
     */
    public static function incrementalSince(int $minSample): ?CarbonInterface
    {
        $row = DB::table(self::TABLE)->where('id', self::ROW)->first();

        if ($row === null || $row->last_started_at === null || $row->full_requested_at !== null) {
            return null;
        }

        if ((int) $row->min_sample !== $minSample) {
            return null;
        }

        return Carbon::parse((string) $row->last_started_at);
    }

    /**
     * Ask the next run to recompute everything.
     *
     * Called wherever answers are DELETED — a deletion leaves no `updated_at` for
     * the incremental run to find, so without this a question's count would keep
     * the answers the retention sweep or an erasure request removed.
     */
    public static function requestFullRecompute(): void
    {
        $now = now();

        DB::table(self::TABLE)->updateOrInsert(
            ['id' => self::ROW],
            ['full_requested_at' => $now, 'updated_at' => $now],
        );

        // updateOrInsert() writes no created_at on insert; stamp it once.
        DB::table(self::TABLE)->where('id', self::ROW)->whereNull('created_at')->update(['created_at' => $now]);
    }

    /**
     * Record a finished run.
     *
     * ⚠️ THE FULL-RUN REQUEST IS CLEARED CONDITIONALLY. A deletion that lands
     * while a full run is walking set `full_requested_at` AFTER `$startedAt`, and
     * the run may already have passed that workspace — so only a request the run
     * started after is cleared; a newer one survives to the next night.
     */
    public static function recordRun(CarbonInterface $startedAt, int $minSample, bool $wasFull): void
    {
        $now = now();

        DB::table(self::TABLE)->updateOrInsert(
            ['id' => self::ROW],
            ['last_started_at' => $startedAt, 'min_sample' => $minSample, 'updated_at' => $now],
        );

        DB::table(self::TABLE)->where('id', self::ROW)->whereNull('created_at')->update(['created_at' => $now]);

        if ($wasFull) {
            DB::table(self::TABLE)
                ->where('id', self::ROW)
                ->where('full_requested_at', '<=', $startedAt)
                ->update(['full_requested_at' => null]);
        }
    }
}
