<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Courses;

use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'course_id' => Course::factory(),
            'section_id' => Section::factory(),
            'chapter_id' => Chapter::factory(),
            'uuid' => Str::uuid(),
            'title' => fake()->sentence(4),
            'type' => fake()->randomElement(['article', 'video', 'pdf', 'file']),
            'content' => fake()->paragraphs(3, true),
            'order' => fake()->numberBetween(1, 20),
            'duration_seconds' => fake()->numberBetween(60, 3600),
            'is_preview' => false,
            'is_free' => false,
            'media' => null,
        ];
    }

    public function preview(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_preview' => true,
        ]);
    }
}
