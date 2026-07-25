<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Courses;

use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chapter>
 */
class ChapterFactory extends Factory
{
    protected $model = Chapter::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'section_id' => Section::factory(),
            'course_id' => Course::factory(),
            'title' => fake()->sentence(2),
            'order' => fake()->numberBetween(1, 10),
        ];
    }
}
