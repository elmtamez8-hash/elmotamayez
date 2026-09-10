<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Marketplace;

use App\Modules\Marketplace\Models\GradeLevel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GradeLevel> */
class GradeLevelFactory extends Factory
{
    protected $model = GradeLevel::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['الابتدائية', 'الإعدادية', 'الثانوية', 'الجامعية']),
            'slug' => Str::slug(fake()->unique()->word()),
            'icon' => 'academic-cap',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
