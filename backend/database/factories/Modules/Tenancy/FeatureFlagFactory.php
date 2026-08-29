<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Tenancy;

use App\Modules\Tenancy\Models\FeatureFlag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FeatureFlag> */
class FeatureFlagFactory extends Factory
{
    protected $model = FeatureFlag::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'feature.'.Str::slug(fake()->unique()->word()),
            // The platform default by default — the row every reader falls back
            // to, and the one a test forgets to create.
            'workspace_id' => 0,
            'enabled' => false,
            'description' => null,
        ];
    }
}
