<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Actions\RollUpLeaderboards;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Gamification\Models\LeaderboardEntry;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Models\Subject;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * The six scopes, the band, the rebuild and the refusals
 * (FR-020 … FR-028 · SC-009 · SC-011 · SC-018 · SC-023 · SC-025 · SC-026).
 *
 * ⚠️ EVERY SCOPE TEST HERE USES TWO WORKSPACES. With one, a "cross-workspace"
 * board is indistinguishable from a per-workspace one and SC-018 passes green
 * over a design that never crossed anything — which is exactly what the
 * workspace-scoped taxonomy would have done before Q8.
 */
beforeEach(function (): void {
    GamificationAction::query()->update(['daily_cap' => null]);

    [$this->workspaceA, $this->ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$this->workspaceB, $this->ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    $this->subject = Subject::query()->firstOrCreate(['slug' => 'math'], ['name' => 'الرياضيات']);

    $make = function ($workspace) {
        return Course::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'subject_id' => $this->subject->getKey(),
            'grade_level' => 'secondary',
        ]);
    };

    $this->courseA = $make($this->workspaceA);
    $this->courseB = $make($this->workspaceB);

    $this->alice = User::factory()->create(['platform_role' => PlatformRole::Student, 'last_name' => 'الكواري']);
    $this->bilal = User::factory()->create(['platform_role' => PlatformRole::Student, 'last_name' => 'العطية']);
});

function earn(User $student, $workspace, $course, int $times, int $sourceOffset = 0): void
{
    foreach (range(1, $times) as $n) {
        app(AwardPoints::class)->handle(new AwardRequest(
            studentUserId: (int) $student->getKey(),
            actionKey: 'session_attended',
            sourceType: 'board',
            sourceId: $sourceOffset + $n,
            workspaceId: (int) $workspace->getKey(),
            courseId: (int) $course->getKey(),
        ));
    }
}

it('builds all six scopes from the same ledger', function (): void {
    $lesson = Lesson::factory()->create([
        'workspace_id' => $this->workspaceA->getKey(),
        'course_id' => $this->courseA->getKey(),
    ]);

    app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $this->alice->getKey(),
        actionKey: 'session_attended',
        sourceType: 'board',
        sourceId: 1,
        workspaceId: (int) $this->workspaceA->getKey(),
        courseId: (int) $this->courseA->getKey(),
        lessonId: (int) $lesson->getKey(),
    ));

    // A student of the OTHER teacher, same subject and grade.
    earn($this->bilal, $this->workspaceB, $this->courseB, 1, 500);

    app(RollUpLeaderboards::class)->handle();

    $keys = LeaderboardEntry::query()->pluck('scope_key')->unique()->values()->all();

    expect($keys)->toContain('platform')
        ->toContain('teacher:'.$this->workspaceA->getKey())
        ->toContain('teacher:'.$this->workspaceB->getKey())
        ->toContain('course:'.$this->courseA->getKey())
        ->toContain('lesson:'.$lesson->getKey())
        ->toContain('subject:'.$this->subject->getKey())
        ->toContain('grade:secondary');

    /*
    | ⚠️ AND THE CROSS-WORKSPACE BOARDS HOLD BOTH STUDENTS, which is the assertion
    | that would have failed before the taxonomy was promoted: "الرياضيات" was a
    | different row per teacher, so `subject:` would have produced TWO boards of
    | one student each while still being called a platform scope.
    */
    expect(LeaderboardEntry::query()->where('scope_key', 'subject:'.$this->subject->getKey())->count())->toBe(2)
        ->and(LeaderboardEntry::query()->where('scope_key', 'grade:secondary')->count())->toBe(2)
        ->and(LeaderboardEntry::query()->where('scope_key', 'platform')->count())->toBe(2)
        // …while the teacher-scoped board holds only their own.
        ->and(LeaderboardEntry::query()->where('scope_key', 'teacher:'.$this->workspaceA->getKey())->count())->toBe(1);
});

it('ranks by points and breaks ties by user, with no gaps', function (): void {
    earn($this->alice, $this->workspaceA, $this->courseA, 3);
    earn($this->bilal, $this->workspaceA, $this->courseA, 1, 100);

    app(RollUpLeaderboards::class)->handle();

    $board = LeaderboardEntry::query()->where('scope_key', 'platform')->orderBy('rank')->get();

    expect($board->pluck('user_id')->all())->toBe([(int) $this->alice->getKey(), (int) $this->bilal->getKey()])
        ->and($board->pluck('rank')->all())->toBe([1, 2]);
});

/*
 * ⚠️ RUN IT TWICE (SC-026).
 *
 * One run is green for ever and proves the opposite of what it claims: an
 * aggregation that double-counts, or a rank that drifts, only shows on the second
 * pass. The precedent is RollupIdempotencyTest in spec 008, written for exactly
 * this after a nightly upsert quietly inserted a new row every night.
 */
it('produces identical numbers when the rollup runs twice', function (): void {
    earn($this->alice, $this->workspaceA, $this->courseA, 3);
    earn($this->bilal, $this->workspaceA, $this->courseA, 2, 100);

    app(RollUpLeaderboards::class)->handle();
    $first = LeaderboardEntry::query()->orderBy('scope_key')->orderBy('user_id')
        ->get(['scope_key', 'period_key', 'user_id', 'points', 'rank', 'level_band'])->toArray();

    app(RollUpLeaderboards::class)->handle();
    $second = LeaderboardEntry::query()->orderBy('scope_key')->orderBy('user_id')
        ->get(['scope_key', 'period_key', 'user_id', 'points', 'rank', 'level_band'])->toArray();

    expect($second)->toBe($first)
        ->and(LeaderboardEntry::query()->count())->toBe(count($first));
});

/*
 * SC-011 — the table is derived, not a source.
 *
 * ⚠️ AND THE DELETE HERE IS A DISASTER-RECOVERY STEP, NOT THE NORMAL PATH. The
 * job upserts and then sweeps what it did not touch, precisely so a board is
 * never empty while it is being built — which is what Redis was rejected for, by
 * the hour instead of at maxmemory.
 */
it('rebuilds an identical board from the ledger after the table is wiped', function (): void {
    earn($this->alice, $this->workspaceA, $this->courseA, 3);
    earn($this->bilal, $this->workspaceA, $this->courseA, 2, 100);

    app(RollUpLeaderboards::class)->handle();
    $before = LeaderboardEntry::query()->orderBy('scope_key')->orderBy('user_id')
        ->get(['scope_key', 'user_id', 'points', 'rank'])->toArray();

    LeaderboardEntry::query()->delete();
    app(RollUpLeaderboards::class)->handle();

    $after = LeaderboardEntry::query()->orderBy('scope_key')->orderBy('user_id')
        ->get(['scope_key', 'user_id', 'points', 'rank'])->toArray();

    expect($after)->toBe($before)->and($after)->not->toBeEmpty();
});

/*
 * ⚠️ THE SLICE IS BY LEVEL BAND FIRST (SC-009).
 *
 * A test that only checked "at most fifty rows" would pass over `floor(rank/50)`,
 * which is the implementation that puts a level-2 student having a good week
 * among level-40 grinders — the exact opposite of why the slice exists.
 */
it('keeps a low-level student out of a high-level board', function (): void {
    earn($this->alice, $this->workspaceA, $this->courseA, 2);

    // Bilal is far up the ladder: his entries were frozen at a high band.
    StudentProgress::query()->updateOrCreate(
        ['user_id' => $this->bilal->getKey()],
        ['uuid' => (string) Str::uuid(), 'level' => 40, 'xp' => 100_000],
    );

    earn($this->bilal, $this->workspaceA, $this->courseA, 9, 100);

    app(RollUpLeaderboards::class)->handle();

    Sanctum::actingAs($this->alice);
    $this->asGuest();

    $body = $this->getJson('/api/v1/gamification/leaderboard?scope=platform')->assertOk()->json();

    // Alice sees her own band, and the high-flyer is not in it — even though he
    // has more points and there is plenty of room in a fifty-row window.
    expect($body['entries'])->toHaveCount(1)
        ->and($body['entries'][0]['points'])->toBe(20)
        ->and($body['my_rank'])->toBe(1);
});

it('refuses a teacher any cross-workspace scope', function (): void {
    Sanctum::actingAs($this->ownerA);

    foreach (['platform', 'subject:'.$this->subject->uuid, 'grade:secondary'] as $scope) {
        $this->getJson('/api/v1/gamification/leaderboard?scope='.$scope)->assertForbidden();
    }
});

it('refuses a student a restricted scope they do not belong to', function (): void {
    Sanctum::actingAs($this->alice);
    $this->asGuest();

    $this->getJson('/api/v1/gamification/leaderboard?scope=course:'.$this->courseB->uuid)
        ->assertForbidden();
});

/*
 * ⚠️ AND A NON-EXISTENT IDENTIFIER ANSWERS THE SAME WAY.
 *
 * A distinct 404 would make the difference between 403 and 404 an oracle: walk
 * `course:{uuid}` until the answers diverge and the platform is enumerated.
 */
it('answers identically for a scope that does not exist', function (): void {
    Sanctum::actingAs($this->alice);
    $this->asGuest();

    $missing = $this->getJson('/api/v1/gamification/leaderboard?scope=course:'.Str::uuid());
    $notMine = $this->getJson('/api/v1/gamification/leaderboard?scope=course:'.$this->courseB->uuid);

    expect($missing->status())->toBe(403)
        ->and($notMine->status())->toBe(403)
        ->and($missing->json('message'))->toBe($notMine->json('message'));
});
