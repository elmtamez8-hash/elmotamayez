<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Courses;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = rtrim(fake()->sentence(4), '.');

        return [
            'workspace_id' => 1,
            'title' => $title,
            'slug' => Str::slug($title.'-'.fake()->randomNumber(4)),
            'description' => fake()->paragraph(),
            'price' => fake()->randomElement([0, 19.99, 49.99, 99.99]),
            'currency' => 'USD',
            'status' => 'draft',
            'visibility' => 'private',
            'is_sequential' => true,
            'language' => 'en',
            'duration_seconds' => 0,
            // Set here rather than left to the column default: a model that was
            // just created does not carry a default it never assigned, so a
            // caller reading $course->structure_version would get null and send
            // it as the concurrency token.
            'structure_version' => 1,
            'created_by' => User::factory(),
            'course_type' => Course::TYPE_RECORDED,
        ];
    }

    public function discounted(float $before = 199.99): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => 99.99,
            'price_before_discount' => $before,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
            'visibility' => 'public',
        ]);
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => 0,
        ]);
    }
}
