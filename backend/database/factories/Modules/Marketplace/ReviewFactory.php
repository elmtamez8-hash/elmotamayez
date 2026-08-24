<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Marketplace;

use App\Models\User;
use App\Modules\Marketplace\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Review> */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'student_id' => User::factory(),
            'rating' => fake()->numberBetween(3, 5),
            'comment' => fake()->sentence(),
            'is_visible' => true,
            // ⚠️ THE THREE AXES ARE LEFT NULL ON PURPOSE, and that is the fixture
            // `SC-011` needs: every review written before spec 010 has them empty,
            // and a factory that filled them would hide the one arithmetic that
            // could drag `average_rating` — and the trust score behind it — to zero.
            'period_start' => now()->toDateString(),
        ];
    }

    public function hidden(): self
    {
        return $this->state(['is_visible' => false]);
    }
}
