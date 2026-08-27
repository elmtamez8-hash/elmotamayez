<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\BadgeAward;
use App\Modules\Gamification\Models\LeaderboardEntry;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Gamification\Support\LeaderboardScope;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\MoveMember;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| SC-012 — قائمةُ الزملاءِ تحملُ ما تصفُه FR-050 ولا حرفاً غيرَه.
|
| ⚠️ قائمةُ سماحٍ لا قائمةَ منعٍ: المشيُ على كلِّ مفتاحٍ في الحمولةِ يفشلُ يومَ
| يُضافُ حقلٌ لم يفكّرْ فيه أحد، بينما «تأكّدْ من غيابِ `attendance`» لا يرى إلّا
| الاسمَ الذي كُتِبَ في الاختبار. هذه هي بنيةُ `PublicExposureTest` نفسُها.
*/

/** Every key the roster may carry, and nothing else (FR-050 · FR-052). */
const ROSTER_ALLOWED = ['uuid', 'name', 'avatar_url', 'badges', 'level', 'rank'];

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->fx = cohortFixture();
    $this->asGuest();
});

/**
 * The context reset, spelled here rather than through `asGuest()`.
 *
 * ⚠️ THAT HELPER IS `protected` AND THESE ARE GLOBAL FUNCTIONS — Pest's file-level
 * functions are not methods of the test case, so calling it is a fatal error and
 * not a failure. `WorkspaceContext` caches its first resolution, so the reset is
 * what keeps a fixture built inside `forWorkspace()` from lending its context to
 * the student's own request.
 */
function rosterAsGuest(): void
{
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);
}

/**
 * A real student: enrolled, in the group, and a member of NO workspace.
 *
 * ⚠️ NO `addWorkspaceMember()` AND NO `setCurrentWorkspace()`. Both stamp
 * `users.last_workspace_id`, which nothing on a student's path ever writes — a
 * fixture using either measures a person the product does not create, which is
 * exactly how five student-facing endpoints shipped dead in 017.
 */
function rosterMember(object $test, string $firstName): User
{
    $fx = $test->fx;
    $student = User::factory()->create(['first_name' => $firstName, 'last_name' => 'الزميل']);

    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx, $student): void {
        Enrollment::create([
            'workspace_id' => $fx['workspace']->getKey(),
            'course_id' => $fx['course']->getKey(),
            'student_user_id' => $student->getKey(),
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);
    });

    rosterAsGuest();
    app(JoinCohort::class)->handle($fx['a'], $student);

    return $student;
}

/** Puts this person on the board the roster reads — the TEACHER scope, keyed by workspace. */
function standingFor(object $test, User $user, int $rank, int $level): void
{
    LeaderboardEntry::factory()->create([
        'scope_key' => LeaderboardScope::Teacher->keyFor((string) $test->fx['workspace']->getKey()),
        'period_key' => app(GamificationCalendar::class)->weekKey(),
        'user_id' => $user->getKey(),
        'rank' => $rank,
        'points' => 120,
        'level_band' => $level,
    ]);

    StudentProgress::factory()->create(['user_id' => $user->getKey(), 'level' => $level]);
}

function rosterOf(object $test, User $reader): array
{
    Sanctum::actingAs($reader);
    rosterAsGuest();

    return $test->getJson('/api/v1/cohorts/'.$test->fx['a']->uuid.'/roster')
        ->assertOk()
        ->json('members');
}

it('carries only the allowlisted fields, for a member with a rank and a badge', function (): void {
    $reader = rosterMember($this, 'سارة');
    $peer = rosterMember($this, 'ليلى');

    standingFor($this, $peer, rank: 7, level: 4);

    $badge = Badge::factory()->create(['key' => 'streak_7', 'name_ar' => 'أسبوعٌ متّصل']);
    BadgeAward::factory()->create([
        'user_id' => $peer->getKey(),
        'badge_key' => $badge->key,
        'awarded_at' => now(),
    ]);

    $members = rosterOf($this, $reader);

    // The fixture has to actually EXERCISE the optional keys, or the walk below
    // passes over a payload that never had them — the vacuous-assertion shape.
    $peerRow = collect($members)->firstWhere('uuid', $peer->uuid);

    expect($peerRow['rank'])->toBe(7)
        ->and($peerRow['level'])->toBe(4)
        ->and($peerRow['badges'])->toHaveCount(1)
        ->and($peerRow['badges'][0]['name_ar'])->toBe('أسبوعٌ متّصل')
        // The accessor, not the column that does not exist — `users` has no
        // `name`, so a constrained eager load naming it returns «  ».
        ->and($peerRow['name'])->toBe('ليلى الزميل');

    foreach ($members as $row) {
        expect(array_diff(array_keys($row), ROSTER_ALLOWED))->toBe([]);

        foreach ($row['badges'] as $chip) {
            expect(array_diff(array_keys($chip), ['key', 'name_ar', 'icon']))->toBe([]);
        }
    }
});

/*
| FR-051 — الغيابُ حالةٌ. «المركز ٠» رقمٌ يُطبَعُ جنبَ اسمِ طالبٍ أمامَ صفِّه،
| والمفتاحُ الحاضرُ بقيمةٍ فارغةٍ يجعلُ الشاشةَ تختارُ بين رسمِ صفرٍ ورسمِ شرطة.
*/
it('omits the rank and the level entirely for a member who has neither', function (): void {
    $reader = rosterMember($this, 'سارة');

    $row = collect(rosterOf($this, $reader))->firstWhere('uuid', $reader->uuid);

    expect($row)->not->toHaveKey('rank')
        ->and($row)->not->toHaveKey('level');
});

/*
| FR-053. The row survives the transfer — it is the history — so the filter is
| what keeps a departed classmate out of a list of who is here now.
*/
it('leaves out a member whose membership was closed', function (): void {
    $reader = rosterMember($this, 'سارة');
    $mover = rosterMember($this, 'هند');

    app(WorkspaceContext::class)->forWorkspace($this->fx['workspace'], function () use ($mover): void {
        app(MoveMember::class)->handle($this->fx['b'], $mover, $this->fx['owner']);
    });

    $this->asGuest();
    $uuids = collect(rosterOf($this, $reader))->pluck('uuid')->all();

    expect($uuids)->toContain($reader->uuid)
        ->and($uuids)->not->toContain($mover->uuid);
});

/*
| ⚠️ والبابُ هنا أضيقُ من بابِ الخيط. FR-046 يمنحُ قراءةَ الأرشيفِ لمن كان عضواً
| يوماً؛ ولا متطلَّبَ يمنحُ من غادرَ رؤيةَ **من في المجموعةِ اليوم**.
*/
it('refuses the list to somebody who left, and to a member of another group', function (): void {
    $left = rosterMember($this, 'هند');
    $outsider = rosterMember($this, 'نور');

    app(WorkspaceContext::class)->forWorkspace($this->fx['workspace'], function () use ($left, $outsider): void {
        app(MoveMember::class)->handle($this->fx['b'], $left, $this->fx['owner']);
        app(MoveMember::class)->handle($this->fx['b'], $outsider, $this->fx['owner']);
    });

    foreach ([$left, $outsider] as $user) {
        Sanctum::actingAs($user);
        $this->asGuest();

        $this->getJson('/api/v1/cohorts/'.$this->fx['a']->uuid.'/roster')->assertForbidden();
    }
});
