<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Modules\Gamification\Enums\LeaderboardPeriod;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Gamification\Support\LeaderboardScope;
use App\Shared\Actions\Action;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rebuild one period's rankings from the award ledger (FR-025 · SC-011 · SC-026).
 *
 * ⚠️ SIX QUERIES, NOT ONE PER COURSE. Each scope type is aggregated in a single
 * statement that GROUPS BY the scope column as well as the student, so adding a
 * thousand courses adds rows, not queries.
 *
 * ⚠️ THE RANK IS FROZEN HERE, by a window function. `COUNT(*) WHERE points > ?`
 * at read time costs exactly as much as the rank itself — the 60,000th student
 * walks 60,000 rows, and SC-008 measures p95, which is the deep half. The
 * `, student_user_id` tie-break fixes a second defect in the same stroke: points
 * are not a unique ordering, so counting gives tied students the same rank while
 * the list orders them arbitrarily, and a student reads `my_rank: 7` then finds
 * their own name ninth IN THE SAME PAYLOAD.
 *
 * ⚠️ UPSERT THEN SWEEP, NEVER DELETE THEN INSERT. Deleting first leaves every
 * board EMPTY for the whole duration of the build — which is exactly what Redis
 * was rejected for, by the hour instead of at `maxmemory`. And SC-011 would not
 * catch it: it compares the final state.
 */
class RollUpLeaderboards extends Action
{
    /** Rows written per statement. Large enough to be few, small enough to bind. */
    private const CHUNK = 500;

    public function __construct(private readonly GamificationCalendar $calendar) {}

    /**
     * @param  string|null  $periodKey  defaults to the period containing now
     * @return int rows written
     */
    public function handle(LeaderboardPeriod $period = LeaderboardPeriod::Week, ?string $periodKey = null): int
    {
        [$from, $to] = $period === LeaderboardPeriod::Week
            ? $this->calendar->weekBounds()
            : $this->termBounds();

        $periodKey ??= $period === LeaderboardPeriod::Week
            ? $this->calendar->weekKey()
            : $this->calendar->termKey();

        // Identifies this pass, so the sweep at the end can tell rows it wrote
        // from rows left over by a previous one.
        $runStamp = Str::random(24);

        $written = 0;

        foreach ($this->scopes() as [$scope, $sub]) {
            $written += $this->writeScope($scope, $sub, $periodKey, $runStamp, $from, $to);
        }

        /*
        | Anything this period still carrying an older stamp belongs to a student
        | whose entire contribution was reversed — they were on the board and are
        | not any more. Removing them is the last step, so the board is never
        | incomplete while it is being built.
        */
        DB::table('leaderboard_entries')
            ->where('period_key', $periodKey)
            ->where('run_stamp', '<>', $runStamp)
            ->delete();

        return $written;
    }

    /**
     * One aggregate per scope type.
     *
     * ⚠️ `DB::table`, WHICH BYPASSES EVERY GLOBAL SCOPE — and that is correct
     * here rather than a shortcut. The rollup is platform-wide by definition; a
     * workspace-scoped read would build the subject and grade boards from one
     * teacher's rows and SC-018 would pass on a single-workspace fixture.
     *
     * @return list<array{0: LeaderboardScope, 1: Builder}>
     */
    private function scopes(): array
    {
        return [
            [LeaderboardScope::Platform, $this->base()->selectRaw("'platform' as scope_id")],
            [LeaderboardScope::Teacher, $this->base()->whereNotNull('workspace_id')->selectRaw('workspace_id as scope_id')],
            [LeaderboardScope::Course, $this->base()->whereNotNull('course_id')->selectRaw('course_id as scope_id')],
            [LeaderboardScope::Lesson, $this->base()->whereNotNull('lesson_id')->selectRaw('lesson_id as scope_id')],

            /*
            | The two cross-workspace taxonomy scopes read `courses`, whose
            | `subject_id` and `grade_level` have existed since spec 006.
            |
            | ⚠️ AND THEY ONLY MEAN THE SAME THING FOR EVERY TEACHER BECAUSE SPEC
            | 009 PROMOTED THE TAXONOMY. While `subjects` carried a workspace_id,
            | "الرياضيات" was a different id per teacher and this GROUP BY produced
            | one board per teacher wearing a platform board's name.
            */
            [LeaderboardScope::Subject, $this->base()
                ->join('courses', 'courses.id', '=', 'award_entries.course_id')
                ->whereNotNull('courses.subject_id')
                ->selectRaw('courses.subject_id as scope_id')],

            [LeaderboardScope::Grade, $this->base()
                ->join('courses', 'courses.id', '=', 'award_entries.course_id')
                ->whereNotNull('courses.grade_level')
                ->selectRaw('courses.grade_level as scope_id')],
        ];
    }

    private function base(): Builder
    {
        return DB::table('award_entries')
            ->selectRaw('award_entries.student_user_id as student_user_id')
            ->selectRaw('SUM(award_entries.xp) as points')
            /*
            | ⚠️ THE BAND COMES FROM THE ENTRIES, NEVER FROM TODAY'S LEVEL. The
            | rebuild has to reproduce the ranking that was actually shown
            | (SC-011), and a student who levelled up mid-period would otherwise be
            | re-sliced into a different band on every pass. MAX is a choice —
            | someone who climbed competes in the higher band — and any choice is
            | acceptable as long as it is DETERMINISTIC, which is what SC-026
            | measures.
            */
            ->selectRaw('MAX(award_entries.level_band) as level_band');
    }

    private function writeScope(
        LeaderboardScope $scope,
        Builder $sub,
        string $periodKey,
        string $runStamp,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
    ): int {
        $sub = $sub
            ->where('award_entries.created_at', '>=', $from)
            // Half-open: `<=` on a timestamp binds midnight and drops the last
            // second of the period.
            ->where('award_entries.created_at', '<', $to)
            ->groupBy('scope_id', 'award_entries.student_user_id');

        /*
        | ⚠️ `cursor()`, NEVER `get()` AND NEVER `lazy()`. `get()` hydrated the
        | whole platform board — one object per (scope, student), six scopes deep —
        | before writing the first row. `lazy()` pages with LIMIT/OFFSET, which
        | re-runs this window-function aggregate once per page over the whole
        | period: the `chunk`-vs-`chunkById` defect with a GROUP BY in front of it.
        | `cursor()` runs the statement once and yields as it goes.
        |
        | The writes below run while it is still open, which is safe because
        | `pdo_mysql` buffers results by default (`config/database.php` does not
        | turn that off). Turn it off and every write here fails with «Cannot
        | execute queries while other unbuffered queries are active» — on MySQL
        | only; SQLite would never say so.
        */
        $ranked = DB::query()
            ->fromSub($sub, 'totals')
            ->selectRaw('scope_id, student_user_id, points, level_band')
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY scope_id, level_band ORDER BY points DESC, student_user_id) as position',
            )
            ->cursor();

        $written = 0;

        foreach ($ranked->chunk(self::CHUNK) as $chunk) {
            /** @var array<string, array{uuid: string, scope_key: string, period_key: string, user_id: int, points: int, level_band: int, rank: int, run_stamp: string, created_at: \DateTimeInterface, updated_at: \DateTimeInterface}> $rows */
            $rows = [];

            foreach ($chunk as $row) {
                $scopeKey = $scope->keyFor((string) $row->scope_id);
                $userId = (int) $row->student_user_id;

                $rows[$scopeKey.'|'.$userId] = [
                    // Explicit, as everywhere a Query Builder write happens here:
                    // no model is booted, so HasUuid never fires.
                    'uuid' => (string) Str::uuid(),
                    'scope_key' => $scopeKey,
                    'period_key' => $periodKey,
                    'user_id' => $userId,
                    'points' => (int) $row->points,
                    'level_band' => (int) $row->level_band,
                    'rank' => (int) $row->position,
                    'run_stamp' => $runStamp,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $written += count($rows);

            $this->writeChanged($rows, $periodKey, $runStamp);
        }

        return $written;
    }

    /**
     * Write the rows whose standing moved; only re-stamp the ones that did not.
     *
     * ⚠️ AN UNCHANGED ROW IS STILL STAMPED, OR THE SWEEP DELETES IT. The sweep at
     * the end of `handle()` removes every row of the period not carrying this
     * pass's stamp — so a row skipped outright would take its student off the
     * board. The stamp is written by PRIMARY KEY, matched on the exact
     * `(scope_key, user_id)` pair in PHP: the read is `whereIn × whereIn`, a
     * cross product, and stamping from it directly would keep a student whose
     * standing in ANOTHER scope of this chunk had been reversed away.
     *
     * What is saved is the rest of the row: the unchanged majority of a board no
     * longer rewrites `points`, `level_band` and `rank`, so it never touches
     * `leaderboard_read_index`, which carries two of them.
     *
     * @param  array<string, array{uuid: string, scope_key: string, period_key: string, user_id: int, points: int, level_band: int, rank: int, run_stamp: string, created_at: \DateTimeInterface, updated_at: \DateTimeInterface}>  $rows  keyed by "scope_key|user_id"
     */
    private function writeChanged(array $rows, string $periodKey, string $runStamp): void
    {
        if ($rows === []) {
            return;
        }

        $existing = DB::table('leaderboard_entries')
            ->where('period_key', $periodKey)
            ->whereIn('scope_key', array_values(array_unique(array_column($rows, 'scope_key'))))
            ->whereIn('user_id', array_values(array_unique(array_column($rows, 'user_id'))))
            ->get(['id', 'scope_key', 'user_id', 'points', 'level_band', 'rank']);

        $unchanged = [];

        foreach ($existing as $entry) {
            $key = $entry->scope_key.'|'.$entry->user_id;
            $fresh = $rows[$key] ?? null;

            if ($fresh !== null
                && (int) $entry->points === $fresh['points']
                && (int) $entry->level_band === $fresh['level_band']
                && (int) $entry->rank === $fresh['rank']) {
                $unchanged[] = (int) $entry->id;
                unset($rows[$key]);
            }
        }

        if ($unchanged !== []) {
            DB::table('leaderboard_entries')
                ->whereIn('id', $unchanged)
                ->update(['run_stamp' => $runStamp]);
        }

        if ($rows !== []) {
            DB::table('leaderboard_entries')->upsert(
                array_values($rows),
                ['scope_key', 'period_key', 'user_id'],
                ['points', 'level_band', 'rank', 'run_stamp', 'updated_at'],
            );
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function termBounds(): array
    {
        // The term is four calendar months, matching GamificationCalendar::termKey().
        [$dayStart] = $this->calendar->dayBounds();

        $localMonth = $dayStart->setTimezone($this->calendar->timezone())->startOfMonth();
        $termStart = $localMonth->subMonths(($localMonth->month - 1) % 4)->startOfMonth();

        return [$termStart->utc(), $termStart->addMonths(4)->utc()];
    }
}
