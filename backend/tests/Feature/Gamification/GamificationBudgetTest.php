<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Listeners\AwardOnAttemptFinalized;
use App\Modules\Gamification\Listeners\AwardOnAttendanceConfirmed;
use App\Modules\Gamification\Listeners\AwardOnMistakeResolved;
use App\Modules\Gamification\Listeners\AwardOnSubmissionGraded;
use App\Modules\Gamification\Listeners\ReverseOnAttendanceOverridden;
use App\Modules\Gamification\Models\LeaderboardEntry;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Identity\Support\PlatformRole;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * What this phase costs: the leaderboard read at scale (SC-008), its query
 * budget, and the guarantee that awarding never slows the thing that caused it
 * (SC-016).
 */
beforeEach(function (): void {
    $this->student = User::factory()->create(['platform_role' => PlatformRole::Student]);
});

/**
 * A hundred thousand rows, written the way `seedCollection()` writes orders.
 *
 * ⚠️ A BULK INSERT WITH `uuid` AND THE TIMESTAMPS PASSED EXPLICITLY. A bulk
 * insert boots no model, so `HasUuid` never fires and the timestamps are never
 * filled — the same fact that makes AwardPoints pass both by hand. On MySQL a
 * missing uuid is silently stored as `''` and every later row collides with it.
 * A hundred thousand factory calls would also cost more than the assertion.
 */
function seedBoard(int $rows, string $scopeKey, string $periodKey, int $ownRank): void
{
    $now = now();
    $userId = (int) User::query()->max('id');

    for ($offset = 0; $offset < $rows; $offset += 1000) {
        $batch = [];
        $size = min(1000, $rows - $offset);

        for ($i = 0; $i < $size; $i++) {
            $rank = $offset + $i + 1;

            $batch[] = [
                'uuid' => (string) Str::uuid(),
                'scope_key' => $scopeKey,
                'period_key' => $periodKey,
                // Distinct and never the reader's own: their row is written after.
                'user_id' => $userId + $rank,
                'points' => $rows - $rank,
                'level_band' => 0,
                'rank' => $rank,
                'run_stamp' => 'bench',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('leaderboard_entries')->insert($batch);
    }

    unset($ownRank);
}

/*
 * SC-008 — a rank read out of a hundred thousand.
 *
 * ⚠️ WHAT THIS MEASURES IS THE QUERY SHAPE, NOT A STOPWATCH. A wall-clock
 * ceiling on a shared runner is a flake generator, and the thing that actually
 * decides this criterion is whether the rank was FROZEN at rollup: the
 * alternative, `COUNT(*) WHERE points > ?`, costs exactly as much as the rank
 * itself — the 60,000th student walks 60,000 rows, and p95 is the deep half. So
 * the assertion is that reading a deep rank costs the same handful of queries as
 * reading a shallow one, and that the time stays inside a ceiling generous
 * enough not to flake.
 */
it('reads a deep rank at the same cost as a shallow one', function (): void {
    $calendar = app(GamificationCalendar::class);
    $periodKey = $calendar->weekKey();

    seedBoard(100_000, 'platform', $periodKey, 60_000);

    // The reader's own row, deliberately deep in the table.
    LeaderboardEntry::query()->create([
        'scope_key' => 'platform',
        'period_key' => $periodKey,
        'user_id' => $this->student->getKey(),
        'points' => 40_000,
        'level_band' => 0,
        'rank' => 60_000,
        'run_stamp' => 'bench',
    ]);

    Sanctum::actingAs($this->student);
    $this->asGuest();

    $started = microtime(true);
    [$queries] = countingQueries(
        fn () => $this->getJson('/api/v1/gamification/leaderboard?scope=platform')->assertOk(),
    );
    $elapsed = (microtime(true) - $started) * 1000;

    // Their own row, plus the window, plus the display names. Bounded, and
    // independent of where in the table the reader sits.
    expect($queries)->toBeLessThanOrEqual(8)
        // Generous by an order of magnitude against the 100ms the criterion
        // names: what would blow this is a full scan, not a slow machine.
        ->and($elapsed)->toBeLessThan(1000);
});

it('returns at most one window however many rows share the band', function (): void {
    $periodKey = app(GamificationCalendar::class)->weekKey();

    seedBoard(500, 'platform', $periodKey, 1);

    Sanctum::actingAs($this->student);
    $this->asGuest();

    $body = $this->getJson('/api/v1/gamification/leaderboard?scope=platform')->assertOk()->json();

    expect(count($body['entries']))->toBeLessThanOrEqual(50);
});

it('costs the same number of queries whether the board holds ten rows or a hundred', function (): void {
    $periodKey = app(GamificationCalendar::class)->weekKey();

    Sanctum::actingAs($this->student);
    $this->asGuest();

    seedBoard(10, 'platform', $periodKey, 1);

    /*
    | ⚠️ WARMED FIRST, AND THAT IS NOT PADDING. The very first read also loads the
    | `platform_settings` row behind the window size, which is memoised for the
    | rest of the process — so an unwarmed first measurement is one query higher
    | than every later one, and the comparison below would fail for a reason that
    | has nothing to do with the number of rows.
    */
    $this->getJson('/api/v1/gamification/leaderboard?scope=platform')->assertOk();

    [$small] = countingQueries(
        fn () => $this->getJson('/api/v1/gamification/leaderboard?scope=platform')->assertOk(),
    );

    LeaderboardEntry::query()->delete();
    seedBoard(100, 'platform', $periodKey, 1);
    [$large] = countingQueries(
        fn () => $this->getJson('/api/v1/gamification/leaderboard?scope=platform')->assertOk(),
    );

    // ⚠️ MEASURED AGAINST A LARGER FIXTURE, not one size. A budget checked at a
    // single size cannot tell a constant cost from a linear one — and the display
    // name is exactly the field a per-row lookup would be written for.
    expect($large)->toBe($small);
});

/*
 * SC-016 — awarding must not slow the operation that triggered it.
 *
 * ⚠️ STRUCTURAL, NOT A STOPWATCH, and deliberately so. Under the `sync` queue
 * connection tests run on, the listener executes inline and a timing comparison
 * would be measuring exactly the thing the design avoids in production — it would
 * flake, and a "fixed" version would assert a ceiling that proves nothing. What
 * makes the criterion true is that every listener is queued, so the operation
 * returns without waiting for any of it.
 */
it('keeps every award listener off the request that triggered it', function (): void {
    $listeners = [
        AwardOnAttendanceConfirmed::class,
        AwardOnAttemptFinalized::class,
        AwardOnMistakeResolved::class,
        AwardOnSubmissionGraded::class,
        ReverseOnAttendanceOverridden::class,
    ];

    foreach ($listeners as $listener) {
        expect(is_subclass_of($listener, ShouldQueue::class))->toBeTrue($listener);
    }
});
