<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Filament\Resources\PlanResource;
use App\Modules\Payments\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| The pricing queue's own screen — the surface without which US4 is unreachable.
|
| ⚠️ THE WHOLE PHASE DEPENDS ON A BUTTON. An unpriced plan is not `sellable()`,
| so the student's catalogue is empty, so nothing is bought, so nothing
| activates — everything below the price works perfectly and nobody can get to
| it. `PATCH /admin/plans/{uuid}/price` existed and no screen reached it, which
| is the fourth time this repository has recorded that shape (`taxonomy.manage`,
| `writeBans.lift`, FR-046's thread).
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->plan = Plan::factory()->unpriced()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->platform = User::factory()->create(['is_super_admin' => true]);
});

it('opens for the platform and for nobody in a tenant role', function (): void {
    /*
    | ⚠️ THE TEACHER MUST FAIL, AND `PlanPolicy::viewAny()` ADMITS THEM. That
    | method has to, because the teacher's own API list is authorised through it —
    | but a Filament LIST never calls the row policy, so a teacher admitted here
    | would read every other teacher's plans with an edit button beside each.
    | `canViewAny()` on the Resource is the platform permission alone.
    */
    $teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);

    foreach ([$this->owner, $teacher] as $tenant) {
        $this->actingAs($tenant);
        app(WorkspaceContext::class)->set($this->workspace);

        expect(PlanResource::canViewAny())->toBeFalse();
    }

    $this->actingAs($this->platform);

    expect(PlanResource::canViewAny())->toBeTrue()
        // A plan is the teacher's product; an officer inventing one in somebody
        // else's workspace is not what this screen is for.
        ->and(PlanResource::canCreate())->toBeFalse();
});

it('never offers to delete a plan, even to a super admin', function (): void {
    // `BasePolicy::before()` waves a super admin past every policy method, and a
    // super admin is exactly who is standing at this screen — which is why the
    // refusal is repeated here and not left to `PlanPolicy::delete()` alone.
    $this->actingAs($this->platform);

    expect(PlanResource::canDelete($this->plan))->toBeFalse()
        ->and(PlanResource::canDeleteAny())->toBeFalse();
});

it('shows every teacher\'s plan, not just the officer\'s own workspace', function (): void {
    /*
    | ⚠️ TWO WORKSPACES, OR THIS PROVES NOTHING. `WorkspaceContext::id()` falls
    | back to `users.last_workspace_id` for a platform officer exactly as for
    | anybody else, so a scoped queue and an unscoped one agree perfectly on a
    | single-workspace fixture — and the scoped one silently shows one arbitrary
    | teacher's plans as though they were the whole queue.
    */
    [$other, $otherOwner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($other, $otherOwner);

    Plan::factory()->unpriced()->create(['workspace_id' => $other->getKey()]);

    $this->actingAs($this->platform);
    app(WorkspaceContext::class)->set($this->workspace);

    expect(PlanResource::getEloquentQuery()->count())->toBe(2)
        // And the badge counts the queue, which is the only thing that tells an
        // officer a teacher is waiting on them.
        ->and(PlanResource::getNavigationBadge())->toBe('2');
});

it('actually writes the price, which mass assignment would silently not', function (): void {
    /*
    | ⚠️ THE DEFECT THIS TEST EXISTS FOR. `price_minor` is deliberately NOT
    | `$fillable` — it is the platform's half of a row two actors write — and
    | Filament's default `handleRecordUpdate()` is `$record->update($data)`.
    | Mass assignment DISCARDS a non-fillable key in SILENCE: no exception, no
    | log, a green «تم الحفظ» toast, and a column that never moved. Three of those
    | shipped on `student_profiles` in spec 013 and every assertion about them
    | passed, because a response echoes what was submitted rather than what was
    | stored.
    |
    | So the page is exercised, not the model.
    */
    $this->actingAs($this->platform);

    $page = new EditPlan;
    $method = new ReflectionMethod($page, 'handleRecordUpdate');
    $method->invoke($page, $this->plan, ['price_minor' => '30000']);

    expect((int) $this->plan->refresh()->price_minor)->toBe(30_000)
        ->and($this->plan->isSellable())->toBeTrue();
});

it('takes a plan off sale when the price is cleared to nothing', function (): void {
    $this->actingAs($this->platform);

    $priced = Plan::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $page = new EditPlan;
    $method = new ReflectionMethod($page, 'handleRecordUpdate');

    // An empty string is what a cleared numeric input actually submits — `null`
    // is what a test writes by hand, and the two take different branches.
    $method->invoke($page, $priced, ['price_minor' => '']);

    expect($priced->refresh()->price_minor)->toBeNull()
        ->and($priced->isSellable())->toBeFalse();
});
