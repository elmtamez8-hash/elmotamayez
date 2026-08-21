<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\ExecuteTeacherOffboarding;
use App\Modules\Compliance\Actions\RequestTeacherOffboarding;
use App\Modules\Compliance\Enums\OffboardingStatus;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\SettlementClearance;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * SC-013 — zero exits completed before the money is settled, and zero permissions
 * left behind afterwards for the teacher OR their assistants.
 *
 * ⚠️ TWO WORKSPACES, AND THE SECOND ONE IS NOT SCENERY. Two separate defects hide
 * in a single-workspace fixture:
 *
 *  1. `EloquentSettlementClearance` is called by a PLATFORM officer about SOMEBODY
 *     ELSE'S workspace, and `WorkspaceContext::id()` falls back to
 *     `users.last_workspace_id` for every user including a super admin. Left inside
 *     the global scope the ledger query returns zero rows, `isCleared()` answers
 *     true, and an exit completes with money outstanding — passing its own test on
 *     one workspace.
 *  2. FR-037 ends permissions "in one workspace only". An assistant who works for
 *     two teachers must keep the job with the one who is staying, and a role
 *     deletion with no team id takes both.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$leaving, $leavingOwner] = $this->createWorkspaceWithOwner(['name' => 'Departing Academy']);
    [$staying, $stayingOwner] = $this->createWorkspaceWithOwner(['name' => 'Staying Academy']);

    $this->workspace = $leaving;
    $this->teacher = $leavingOwner;
    $this->staying = $staying;

    // One person, two jobs. The exit must end exactly one of them.
    $this->assistant = $this->addWorkspaceMember($leaving, Roles::ASSISTANT_TEACHER);
    WorkspaceMember::query()->create([
        'workspace_id' => $staying->getKey(),
        'user_id' => $this->assistant->getKey(),
        'role' => Roles::ASSISTANT_TEACHER,
        'joined_at' => now(),
    ]);

    app(WorkspaceContext::class)->forWorkspace($staying, function (): void {
        $this->assistant->assignRole(Roles::ASSISTANT_TEACHER);
    });

    $this->profile = TeacherProfile::factory()->create([
        'workspace_id' => $leaving->getKey(),
        'user_id' => $leavingOwner->getKey(),
        'is_publicly_listed' => true,
    ]);

    $this->officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER);

    /*
    | ⚠️ THE OFFICER IS GIVEN A FALLBACK WORKSPACE, WHICH IS WHAT ARMS CASE 1
    | ABOVE. With the column null the context resolves to null, `WorkspaceScope`
    | adds no condition, and a clearance query that forgot its bypass passes
    | anyway. Every real officer has one: they open the panel from somewhere.
    */
    $this->officer->forceFill(['last_workspace_id' => $staying->getKey()])->save();
});

/**
 * ⚠️ THE NOTICE IS PUT IN THE PAST BEFORE EVERY MONEY CASE, AND WITHOUT THIS THE
 * WHOLE FILE PROVED NOTHING.
 *
 * Completion has TWO conditions — the books balanced and the announced notice run
 * out — so a fixture with a live notice is refused whatever the ledger says.
 * Deleting the settlement check entirely left all nine cases green: measured, by
 * removing it. The date is cleared first so the money is the only thing left
 * standing in the way, which is what these cases claim to be about.
 */
function noticeIsOver(TeacherOffboarding $offboarding): void
{
    TeacherOffboarding::query()
        ->whereKey($offboarding->getKey())
        ->update(['notice_ends_at' => now()->subDay()]);
}

function owe(int $workspaceId, int $profileId, int $minor): void
{
    LedgerEntry::query()->create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'teacher_profile_id' => $profileId,
        'type' => LedgerEntryType::Bonus->value,
        'amount_minor' => $minor,
        'currency' => 'QAR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('refuses to complete an exit while the teacher is still owed money', function (): void {
    Queue::fake();

    owe((int) $this->workspace->getKey(), (int) $this->profile->getKey(), 50_000);

    $offboarding = app(RequestTeacherOffboarding::class)->handle($this->workspace, $this->teacher);

    expect($offboarding->status)->toBe(OffboardingStatus::SettlementPending);

    noticeIsOver($offboarding);

    Sanctum::actingAs($this->officer);

    $this->postJson("/api/v1/manage/compliance/offboardings/{$offboarding->uuid}/execute")
        ->assertStatus(422);

    expect($offboarding->refresh()->status)->not->toBe(OffboardingStatus::Completed)
        // And nothing was revoked on the way to the refusal.
        ->and(WorkspaceMember::query()->where('workspace_id', $this->workspace->getKey())->count())
        ->toBeGreaterThan(0);
});

/*
 * ⚠️ THE MIRROR CASE, AND IT IS THE ONE A NET WOULD MISS. A teacher who OWES the
 * platform is as unsettled as one who is owed — `SettlementStanding` carries two
 * numbers rather than a net precisely because +500 and −500 net to zero while
 * being two live debts.
 */
it('refuses to complete an exit while the teacher owes the platform', function (): void {
    Queue::fake();

    owe((int) $this->workspace->getKey(), (int) $this->profile->getKey(), -30_000);

    $offboarding = app(RequestTeacherOffboarding::class)->handle($this->workspace, $this->teacher);

    noticeIsOver($offboarding);

    Sanctum::actingAs($this->officer);

    $this->postJson("/api/v1/manage/compliance/offboardings/{$offboarding->uuid}/execute")
        ->assertStatus(422);

    expect($offboarding->refresh()->status)->not->toBe(OffboardingStatus::Completed);
});

/*
 * ⚠️ AND A REFUSED EXIT IS RETRYABLE. The completion claim matches
 * `status = notice_period`, so parking a refused row anywhere the claim cannot
 * see would strand it for ever — the dead end US4's `executed_by_user_id`
 * produced, reached through a status instead of a column. Measured through the
 * REAL ROUTE, twice, which is the only shape that fails.
 */
it('completes the exit once the books balance, and revokes access in one workspace only', function (): void {
    Queue::fake();

    owe((int) $this->workspace->getKey(), (int) $this->profile->getKey(), 50_000);

    $offboarding = app(RequestTeacherOffboarding::class)->handle($this->workspace, $this->teacher);

    noticeIsOver($offboarding);

    Sanctum::actingAs($this->officer);
    $this->postJson("/api/v1/manage/compliance/offboardings/{$offboarding->uuid}/execute")->assertStatus(422);

    // Paid: the balance falls back to zero, exactly as a payout would leave it.
    owe((int) $this->workspace->getKey(), (int) $this->profile->getKey(), -50_000);

    $this->postJson("/api/v1/manage/compliance/offboardings/{$offboarding->uuid}/execute")->assertOk();

    expect($offboarding->refresh()->status)->toBe(OffboardingStatus::Completed)
        // FR-037 — nobody is left inside the departed workspace…
        ->and(WorkspaceMember::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(0)
        // …and the assistant's OTHER job is untouched, which is the half that a
        // role deletion with no team id would silently take as well.
        ->and(WorkspaceMember::query()
            ->where('workspace_id', $this->staying->getKey())
            ->where('user_id', $this->assistant->getKey())
            ->count())->toBe(1);

    app(WorkspaceContext::class)->forWorkspace($this->staying, function (): void {
        expect($this->assistant->fresh()?->hasRole(Roles::ASSISTANT_TEACHER))->toBeTrue();
    });
});

it('refuses to complete before the announced notice has run out', function (): void {
    Queue::fake();

    $offboarding = app(RequestTeacherOffboarding::class)->handle($this->workspace, $this->teacher);

    // Settled from the start — the only thing standing in the way is the date.
    expect($offboarding->status)->toBe(OffboardingStatus::NoticePeriod);

    Sanctum::actingAs($this->officer);

    $this->postJson("/api/v1/manage/compliance/offboardings/{$offboarding->uuid}/execute")
        ->assertStatus(422);

    expect($offboarding->refresh()->status)->toBe(OffboardingStatus::NoticePeriod);
});

it('keeps the exit endpoints out of a teacher s hands', function (): void {
    Queue::fake();

    $offboarding = app(RequestTeacherOffboarding::class)->handle($this->workspace, $this->teacher);

    Sanctum::actingAs($this->teacher);

    $this->getJson('/api/v1/manage/compliance/offboardings')->assertStatus(403);
    $this->postJson("/api/v1/manage/compliance/offboardings/{$offboarding->uuid}/execute")->assertStatus(403);
});

/*
 * ⚠️ AND AN ASSISTANT MAY NOT WIND DOWN SOMEBODY ELSE'S BUSINESS. The request
 * endpoint answers to `workspaces.owner_user_id`, not to membership: a broad role
 * inside a teacher's workspace would otherwise be enough to notify every student,
 * pull the public listing and queue the exit.
 */
it('lets only the workspace owner ask to leave', function (): void {
    Queue::fake();

    Sanctum::actingAs($this->assistant);
    $this->postJson('/api/v1/teaching/offboarding')->assertStatus(403);

    expect(TeacherOffboarding::query()->withoutWorkspaceScope()->count())->toBe(0);

    Sanctum::actingAs($this->teacher);
    $this->postJson('/api/v1/teaching/offboarding')->assertStatus(201);

    expect(TeacherOffboarding::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('answers a second request with the exit already open rather than a second row', function (): void {
    Queue::fake();

    Sanctum::actingAs($this->teacher);

    $this->postJson('/api/v1/teaching/offboarding')->assertStatus(201);
    $this->postJson('/api/v1/teaching/offboarding')->assertStatus(200);

    expect(TeacherOffboarding::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('signs the departed teacher out everywhere', function (): void {
    Queue::fake();

    $offboarding = app(RequestTeacherOffboarding::class)->handle($this->workspace, $this->teacher);

    $this->teacher->createToken('phone');

    TeacherOffboarding::query()
        ->whereKey($offboarding->getKey())
        ->update(['notice_ends_at' => now()->subDay()]);

    app(ExecuteTeacherOffboarding::class)->handle($offboarding, $this->officer);

    expect($this->teacher->fresh()?->tokens()->count())->toBe(0);
});

it('leaves an unrelated teacher s workspace completely alone', function (): void {
    Queue::fake();

    $stayingOwner = User::query()->findOrFail($this->staying->owner_user_id);

    $offboarding = app(RequestTeacherOffboarding::class)->handle($this->workspace, $this->teacher);

    TeacherOffboarding::query()
        ->whereKey($offboarding->getKey())
        ->update(['notice_ends_at' => now()->subDay()]);

    app(ExecuteTeacherOffboarding::class)->handle($offboarding, $this->officer);

    expect(WorkspaceMember::query()->where('workspace_id', $this->staying->getKey())->count())
        ->toBeGreaterThan(0)
        ->and($stayingOwner->fresh()?->tokens()->count())->toBe(0);
});

/*
 * ⚠️ THE SCOPE BYPASS, MEASURED DIRECTLY — AND THE ROUTE CASES ABOVE DO NOT PROVE
 * IT, WHICH IS WHY THIS EXISTS.
 *
 * `EloquentSettlementClearance` is asked by a PLATFORM officer about SOMEBODY
 * ELSE'S workspace. Left inside the global scope every one of its queries answers
 * about whatever workspace the reader's context happens to hold — zero rows, an
 * empty standing, `isCleared()` true, and an exit completed with money owed in
 * both directions.
 *
 * Removing `withoutWorkspaceScope()` from the class left all nine cases above
 * GREEN: measured, by removing it. `WorkspaceContext` caches its resolution and
 * the request path never lands on the other workspace, so the arming those cases
 * claim never happens. This one puts the reader INSIDE the staying workspace
 * explicitly and asks about the departing one, which is the shape a real officer's
 * request takes and the only shape that fails.
 */
it('reads the departing workspace s books from inside another workspace s context', function (): void {
    owe((int) $this->workspace->getKey(), (int) $this->profile->getKey(), 50_000);

    $standing = app(WorkspaceContext::class)->forWorkspace(
        $this->staying,
        fn () => app(SettlementClearance::class)
            ->outstandingFor($this->teacher, (int) $this->workspace->getKey()),
    );

    expect($standing->owedToTeacherMinor)->toBe(50_000)
        ->and($standing->isCleared())->toBeFalse();
});
