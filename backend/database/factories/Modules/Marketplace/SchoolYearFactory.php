<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Marketplace;

use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\SchoolYear;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SchoolYear> */
class SchoolYearFactory extends Factory
{
    protected $model = SchoolYear::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // `grade_level_id` is NOT NULL, so the factory has to supply one —
            // and it resolves an EXISTING stage first: the taxonomy is seeded
            // before every Feature test, so making a fresh one here would leave
            // fixtures pointing at a stage no picker ever offers.
            'grade_level_id' => fn (): int => GradeLevel::query()->value('id')
                ?? GradeLevel::factory()->create()->getKey(),
            'name_ar' => 'الصف '.fake()->numberBetween(1, 12),
            'slug' => Str::slug(fake()->unique()->word()),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
