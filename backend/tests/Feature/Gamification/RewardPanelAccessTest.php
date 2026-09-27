<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Filament\Resources\RedemptionResource;
use App\Modules\Gamification\Filament\Resources\RewardResource;
use App\Modules\Gamification\Models\Redemption;
use App\Modules\Gamification\Models\Reward;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| The reward shop and the redemption queue in `/admin`.
|
| ⚠️ Both doors read a TENANT permission (`rewards.manage`,
| `redemptions.fulfill`) over a scoped list, on the stated reasoning that «the
| reader is a member of the workspace». No teacher reaches `/admin`; the reader
| is platform staff — and one who owns a workspace saw that one shop as the
| platform's, while the super admin with a workspace saw the same short list.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->home, $this->homeOwner] = $this->createWorkspaceWithOwner(['name' => 'مساحة الموظّف']);
    [$this->away] = $this->createWorkspaceWithOwner(['name' => 'مساحة المدرّس']);

    $this->reward = Reward::factory()->create(['workspace_id' => $this->away->getKey(), 'title' => 'درعُ السلسلة']);
    $this->redemption = Redemption::factory()->create([
        'workspace_id' => $this->away->getKey(),
        'reward_id' => $this->reward->getKey(),
    ]);
});

function rewardPanelAs(User $user, Workspace $home): void
{
    test()->actingAs($user);
    app()->forgetInstance(WorkspaceContext::class);
    test()->setCurrentWorkspace($home, $user);
}

it('stays shut to platform staff who hold the shop permissions in their own workspace', function (string $role): void {
    $officer = makePlatformStaff($role, $this->homeOwner);
    rewardPanelAs($officer, $this->home);

    expect(RewardResource::canViewAny())->toBeFalse()
        ->and(RedemptionResource::canViewAny())->toBeFalse();
})->with([
    'finance officer' => [Roles::FINANCE_ADMIN],
    'compliance officer' => [Roles::COMPLIANCE_OFFICER],
]);

it('shows the super admin who has a workspace every shop, with the pending count and the reward', function (): void {
    rewardPanelAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    $reward = RewardResource::getEloquentQuery()->whereKey($this->reward->getKey())->first();
    $redemption = RedemptionResource::getEloquentQuery()->whereKey($this->redemption->getKey())->first();

    expect(RewardResource::canViewAny())->toBeTrue()
        ->and($reward?->getAttribute('pending_redemptions_count'))->toBe(1)
        ->and(RedemptionResource::canViewAny())->toBeTrue()
        ->and($redemption?->getRelationValue('reward')?->getAttribute('title'))->toBe('درعُ السلسلة');
});
