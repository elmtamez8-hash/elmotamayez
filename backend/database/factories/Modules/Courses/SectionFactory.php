<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Courses;

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    protected $model = Section::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'course_id' => Course::factory(),
            'title' => fake()->sentence(3),
            'order' => fake()->numberBetween(1, 10),
            'is_published' => true,
        ];
    }
}
