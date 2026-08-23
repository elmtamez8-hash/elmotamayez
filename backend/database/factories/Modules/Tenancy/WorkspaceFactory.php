<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /*
    | ⚠️ DECLARED, BECAUSE LARAVEL'S GUESSER CANNOT REACH IT FROM HERE.
    |
    | `Factory::modelName()` strips `Database\Factories\` and looks for
    | `App\Models\Modules\Tenancy\Workspace`; that does not exist, so it falls
    | back to the app namespace plus the basename — `App\Workspace` — and every
    | `Workspace::factory()` died with "Class App\Workspace not found". Every
    | other factory in this tree names its model, and this one did not.
    |
    | It stayed invisible because nothing ever resolved it: workspaces are built
    | by `createWorkspaceWithOwner()` and by `CreateWorkspace`, never by the
    | factory. What it left behind were three loaded guns —
    | `RewardFactory`, `RedemptionFactory` and `CoinBalanceFactory` all default
    | `workspace_id` to `Workspace::factory()`, which throws the first time any of
    | them is called without one supplied.
    */
    protected $model = Workspace::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $types = ['teacher', 'academy', 'school'];
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name.'-'.fake()->randomNumber(4)),
            'type' => fake()->randomElement($types),
            'owner_user_id' => User::factory(),
            'settings' => null,
        ];
    }
}
