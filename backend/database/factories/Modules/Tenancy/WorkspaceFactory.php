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
