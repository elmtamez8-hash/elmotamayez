<?php

declare(strict_types=1);

use App\Filament\Resources\WorkspaceResource;
use App\Filament\Resources\WorkspaceResource\Pages\CreateWorkspace as CreateWorkspacePage;
use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Tenancy\Actions\CreateWorkspace as CreateWorkspaceAction;
use App\Modules\Tenancy\DTOs\CreateWorkspaceDTO;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Models\Workspace;
use Livewire\Livewire;

/**
 * Spec 025 · FR-009 — the one door left open, and it is a screen that had to be
 * built rather than a check that had to be added.
 *
 * Measured before this spec: `WorkspaceResource::canCreate()` returned `false`
 * and the only registered page was `index`. So closing `POST /workspaces` without
 * this screen would have left a platform administrator unable to create a
 * workspace anywhere, for anybody, by any means.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_super_admin' => true]);
    $this->teacher = User::factory()->create([
        'first_name' => 'خالد',
        'last_name' => 'عبد الباسط',
        'platform_role' => PlatformRole::Teacher,
    ]);
});

it('creates a workspace for a chosen teacher, with its roles seeded', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateWorkspacePage::class)
        ->fillForm([
            'owner_user_id' => $this->teacher->getKey(),
            'name' => 'خالد عبد الباسط',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('owner_user_id', $this->teacher->getKey())->sole();

    /*
    | ⚠️ THE FOUR ROLES ARE THE WHOLE POINT OF THE ASSERTION, not a detail.
    | They exist only because the page goes through `CreateWorkspace`, which
    | dispatches `WorkspaceCreated`. A screen that let Filament call
    | `Workspace::create()` would pass every other assertion here and produce a
    | workspace whose owner can do nothing inside it — the production `platform`
    | row's defect, rebuilt from inside the panel.
    */
    expect(Role::query()->where('team_id', $workspace->getKey())->count())->toBe(4)
        ->and($workspace->type)->toBe('teacher')
        ->and($this->teacher->fresh()?->last_workspace_id)->toBe($workspace->getKey());
});

it('records the act under the administrator who performed it', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateWorkspacePage::class)
        ->fillForm(['owner_user_id' => $this->teacher->getKey(), 'name' => 'خالد'])
        ->call('create')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('owner_user_id', $this->teacher->getKey())->sole();

    // FR-009's second half — «and the act is recorded in their name». It was not
    // there before this spec: `CreateWorkspace` had zero activity calls, while
    // `UpdateWorkspace` and `RemoveMember` both logged.
    $entry = DB::table('activity_log')
        ->where('subject_type', $workspace->getMorphClass())
        ->where('subject_id', $workspace->getKey())
        ->where('description', 'created')
        ->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->causer_id)->toBe($this->admin->getKey());
});

it('refuses a second workspace for an owner who already has one', function (): void {
    [, $owner] = $this->createWorkspaceWithOwner();

    $this->actingAs($this->admin);

    /*
    | FR-008, and this is the ONLY surface it is measurable from. Over the API a
    | teacher who owns one is refused `403` a step earlier — they hold no
    | `workspaces.create` at all — so the second-workspace rule is never reached
    | there. Here the caller does hold the permission.
    |
    | ⚠️ TWO GUARDS, AND THE TEST ASSERTS BOTH. The picker does not offer an owner
    | who already has a workspace, so the form refuses first — but a filtered list
    | shapes one request and not the next, so the rule itself lives in the Action.
    | Asserting only the form would be measuring the SCREEN and calling it a rule.
    */
    Livewire::test(CreateWorkspacePage::class)
        ->fillForm(['owner_user_id' => $owner->getKey(), 'name' => 'مكان ثانٍ'])
        ->call('create')
        ->assertHasFormErrors(['owner_user_id']);

    expect(fn () => app(CreateWorkspaceAction::class)->handle(
        new CreateWorkspaceDTO(name: 'مكان ثانٍ', type: 'teacher'),
        $owner,
    ))->toThrow(DomainException::class, 'يملك مكان عمل بالفعل');

    expect(Workspace::query()->where('owner_user_id', $owner->getKey())->count())->toBe(1);
});

it('refuses to install a student as an owner', function (): void {
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    $this->actingAs($this->admin);

    /*
    | SC-005 — «zero students or guardians own a workspace, before the change and
    | after it». Installing one would hand them `tenant-owner`'s 68 permissions
    | and break the premise half the product rests on: a student belongs to no
    | workspace, which is exactly why `WorkspaceScope` is inert for them.
    |
    | Both halves again — the picker offers only teachers, and the Action refuses
    | regardless of what reaches it. Seeders run inside `Model::unguarded()` and
    | carry no form at all, so the Action is the only guard some callers ever meet.
    */
    Livewire::test(CreateWorkspacePage::class)
        ->fillForm(['owner_user_id' => $student->getKey(), 'name' => 'مكان لطالب'])
        ->call('create')
        ->assertHasFormErrors(['owner_user_id']);

    expect(fn () => app(CreateWorkspaceAction::class)->handle(
        new CreateWorkspaceDTO(name: 'مكان لطالب', type: 'teacher'),
        $student,
    ))->toThrow(DomainException::class, 'إلا لحساب مدرّس');

    expect(Workspace::query()->where('owner_user_id', $student->getKey())->exists())->toBeFalse();
});

it('is closed to anybody who is not a platform administrator', function (): void {
    [, $owner] = $this->createWorkspaceWithOwner();

    $this->actingAs($owner);

    // A teacher holds no `workspaces.create` — it sits in no tenant role — so the
    // panel offers nothing the API would refuse. One spelling, two surfaces.
    expect(WorkspaceResource::canCreate())->toBeFalse();
});
