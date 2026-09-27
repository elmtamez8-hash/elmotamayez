<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Filament\Resources\RoleResource;
use App\Modules\Tenancy\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Modules\Tenancy\Filament\Resources\RoleResource\Pages\EditRole;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| The roles screen in `/admin`, read by somebody who HAS a workspace.
|
| ⚠️ `RoleScreenTest` signs the super admin in with no workspace, so the
| context is null and `TeamRoleScope` is inert — which is the one fixture in
| which none of the three defects below can be seen. A super admin who owns a
| workspace saw only that workspace's roles; `Gate::before` let them delete a
| DEFAULT role that `RolePolicy::delete()` refuses; and a platform officer who
| holds `roles.manage` in their own workspace could create a role in anybody's.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->home, $this->homeOwner] = $this->createWorkspaceWithOwner(['name' => 'مساحة القارئ']);
    [$this->away] = $this->createWorkspaceWithOwner(['name' => 'مساحة مدرّس آخر']);

    $this->awayTeacherRole = Role::query()->withoutTeamScope()
        ->where('team_id', $this->away->getKey())
        ->where('name', Roles::TEACHER)
        ->firstOrFail();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function rolePanelAs(User $user, Workspace $home): void
{
    test()->actingAs($user);
    app()->forgetInstance(WorkspaceContext::class);
    test()->setCurrentWorkspace($home, $user);
}

it('lists every workspace\'s roles for a super admin who has a workspace, and no platform role', function (): void {
    rolePanelAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    $teams = RoleResource::getEloquentQuery()->pluck('team_id')->unique()->all();

    expect($teams)->toContain($this->home->getKey(), $this->away->getKey())
        ->and($teams)->not->toContain(null);
});

it('refuses to delete a default role — even to the super admin', function (): void {
    rolePanelAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    expect(RoleResource::canDelete($this->awayTeacherRole))->toBeFalse();

    // The button is authorised through `getDeleteAuthorizationResponse()`, not
    // `canDelete()` — which is why the refusal overrides the former.
    Livewire::test(EditRole::class, ['record' => $this->awayTeacherRole->getKey()])
        ->assertActionHidden(DeleteAction::class);

    expect(Role::query()->withoutTeamScope()->whereKey($this->awayTeacherRole->getKey())->exists())->toBeTrue();
});

it('still deletes a role somebody invented', function (): void {
    rolePanelAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    $custom = Role::query()->create([
        'name' => 'course-reviewer',
        'guard_name' => 'web',
        'team_id' => $this->away->getKey(),
    ]);

    expect(RoleResource::canDelete($custom))->toBeTrue();

    Livewire::test(EditRole::class, ['record' => $custom->getKey()])
        ->callAction(DeleteAction::class);

    expect(Role::query()->withoutTeamScope()->whereKey($custom->getKey())->exists())->toBeFalse();
});

it('lets a platform officer with roles.manage create a role in their own workspace only', function (): void {
    $officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER, $this->homeOwner);
    rolePanelAs($officer, $this->home);

    expect(RoleResource::workspaceOptions())->toBe([$this->home->getKey() => $this->home->name]);

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'intruder', 'team_id' => $this->away->getKey()])
        ->call('create');

    expect(Role::query()->withoutTeamScope()->where('name', 'intruder')->exists())->toBeFalse();

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'home-reviewer', 'team_id' => $this->home->getKey()])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Role::query()->withoutTeamScope()->where('name', 'home-reviewer')->value('team_id'))
        ->toBe($this->home->getKey());
});
