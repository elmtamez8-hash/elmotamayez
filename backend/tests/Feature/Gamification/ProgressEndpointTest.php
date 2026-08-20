<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Models\BadgeAward;
use App\Modules\Gamification\Support\ProgressWriter;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * The profile endpoint, and the two gates in front of a teacher reading a student
 * (FR-041 · FR-043 · NFR-001أ).
 */
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();
});

it('shows a student their own profile', function (): void {
    app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $this->student->getKey(),
        actionKey: 'session_attended',
        sourceType: 'class_session',
        sourceId: 1,
        workspaceId: (int) $this->workspace->getKey(),
    ));

    Sanctum::actingAs($this->student);
    $this->asGuest();

    $response = $this->getJson('/api/v1/gamification/me')->assertOk();

    expect($response->json('xp'))->toBe(10)
        ->and($response->json('coin_balances'))->toHaveCount(1)
        ->and($response->json('coin_balances.0.coins'))->toBe(5);
});

/*
 * ⚠️ AND NO TOTAL, EVER.
 *
 * A purse belongs to one teacher, so no sum across them is spendable. A displayed
 * total promises exactly what the shop refuses on the student's first attempt.
 */
it('sends no total across teachers, because there is no correct one', function (): void {
    Sanctum::actingAs($this->student);
    $this->asGuest();

    $body = $this->getJson('/api/v1/gamification/me')->assertOk()->json();

    expect(array_keys($body))->not->toContain('coins_total')
        ->and(array_keys($body))->not->toContain('coins');
});

it('refuses a teacher without the permission', function (): void {
    Sanctum::actingAs($this->owner);

    // The owner holds progress.view.student by default, so the check needs a role
    // that does not — a plain member.
    $stranger = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    Sanctum::actingAs($stranger);

    $this->getJson("/api/v1/gamification/students/{$this->student->uuid}")->assertForbidden();
});

/*
 * ⚠️ THE PERMISSION IS NOT SUFFICIENT ON ITS OWN, and this is the case that says
 * so. `student_progress` is platform-owned and carries no workspace_id, so
 * nothing but this check stands between a teacher and every student on the site.
 */
it('refuses a teacher reading a student who is not enrolled with them', function (): void {
    Sanctum::actingAs($this->owner);

    $this->getJson("/api/v1/gamification/students/{$this->student->uuid}")->assertForbidden();
});

it('answers the same for an account that does not exist', function (): void {
    Sanctum::actingAs($this->owner);

    $missing = $this->getJson('/api/v1/gamification/students/'.Str::uuid());
    $notMine = $this->getJson("/api/v1/gamification/students/{$this->student->uuid}");

    // Identical, so the endpoint cannot be used to ask whether an account exists.
    expect($missing->status())->toBe(403)
        ->and($notMine->status())->toBe(403)
        ->and($missing->json('message'))->toBe($notMine->json('message'));
});

it('lets a teacher read a student enrolled with them', function (): void {
    $course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->createEnrollment($this->workspace, $course, $this->student);

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/v1/gamification/students/{$this->student->uuid}")
        ->assertOk()
        ->assertJsonStructure(['xp', 'level', 'current_streak', 'badges', 'coin_balances']);
});

/*
 * ⚠️ MEASURED AGAINST DOUBLE THE FIXTURE.
 *
 * A Resource runs once per row, so a query inside one is an N+1 by construction —
 * and a budget checked against a single fixture size cannot tell a constant from
 * a linear cost. Three lookups were waiting to be written into this payload: the
 * badge name per badge, the teacher name per purse, and the level.
 */
it('costs the same number of queries however many badges and purses there are', function (): void {
    $writer = app(ProgressWriter::class);
    $writer->progressFor((int) $this->student->getKey());

    $seed = function (int $badges, int $purses): void {
        BadgeAward::query()->where('user_id', $this->student->getKey())->delete();

        foreach (range(1, $badges) as $n) {
            BadgeAward::factory()->create([
                'user_id' => $this->student->getKey(),
                'badge_key' => "badge_{$n}",
            ]);
        }

        foreach (range(1, $purses) as $n) {
            [$workspace] = $this->createWorkspaceWithOwner(['name' => "Purse {$n}"]);
            app(ProgressWriter::class)->coinBalanceFor(
                (int) $this->student->getKey(),
                (int) $workspace->getKey(),
            );
        }
    };

    Sanctum::actingAs($this->student);
    $this->asGuest();

    $seed(2, 2);
    [$small] = countingQueries(fn () => $this->getJson('/api/v1/gamification/me')->assertOk());

    $seed(4, 2);
    [$large] = countingQueries(fn () => $this->getJson('/api/v1/gamification/me')->assertOk());

    expect($large)->toBe($small);
});
