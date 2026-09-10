<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Marketplace;

use App\Modules\Marketplace\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Region> */
class RegionFactory extends Factory
{
    protected $model = Region::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['الدوحة', 'الريان', 'الوكرة', 'الخور']),
            'slug' => Str::slug(fake()->unique()->word()),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
