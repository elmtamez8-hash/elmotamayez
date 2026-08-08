<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Courses;

use App\Modules\Courses\Enums\ContentStatus;
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
            // No `order` here on purpose. HasSiblingOrder assigns the next free
            // position, which a unique(course_id, order) index now requires; a
            // random number in a range of ten collides on the third section of
            // the same course roughly a fifth of the time, and a suite that
            // fails one run in five is a suite people learn to re-run.
            'status' => ContentStatus::Published,
        ];
    }
}
