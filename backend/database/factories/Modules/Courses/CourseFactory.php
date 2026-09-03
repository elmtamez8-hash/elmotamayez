<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Courses;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\Subject;
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
            'price_minor' => fake()->randomElement([0, 1999, 4999, 9999]),
            'currency' => 'QAR',
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
            /*
            | ⚠️ EVERY COURSE CARRIES A SUBJECT, INCLUDING EVERY FIXTURE. It is
            | required at the door since it was found NULL on 77 of 77 real rows —
            | the column arrived with 007's pricing migration and no writer ever
            | set it — and a factory that left it null would build the exact state
            | the rule exists to end, so every subject-shaped assertion would be
            | measuring an empty column.
            |
            | `firstOrCreate` on the slug, not a new row per course: `subjects` is
            | PLATFORM reference data deduped by slug (the constitution names that
            | failure), so a factory minting one each time would rebuild the
            | duplication spec 010's migration removed.
            */
            'subject_id' => Subject::query()->firstOrCreate(
                ['slug' => 'general'],
                ['name_ar' => 'عامّ'],
            )->getKey(),
        ];
    }

    public function discounted(int $beforeMinor = 19999): static
    {
        return $this->state(fn (array $attributes) => [
            'price_minor' => 9999,
            'price_before_discount_minor' => $beforeMinor,
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
            'price_minor' => 0,
        ]);
    }

    /**
     * A course whose promotional video has been reviewed and approved (018).
     *
     * ⚠️ The status is set here as well as the id, because `Course::$fillable`
     * deliberately excludes the status — but `SeedCommand` and factories run
     * unguarded, so this state writes it directly rather than through the
     * action. That is exactly why the action is still the only writer in
     * application code.
     */
    public function withApprovedPromoVideo(string $videoId = 'dQw4w9WgXcQ'): static
    {
        return $this->state(fn (array $attributes) => [
            'promo_video_id' => $videoId,
            'promo_video_status' => Course::PROMO_APPROVED,
            'promo_video_reviewed_at' => now(),
        ]);
    }

    public function withPendingPromoVideo(string $videoId = 'dQw4w9WgXcQ'): static
    {
        return $this->state(fn (array $attributes) => [
            'promo_video_id' => $videoId,
            'promo_video_status' => Course::PROMO_PENDING,
        ]);
    }
}
