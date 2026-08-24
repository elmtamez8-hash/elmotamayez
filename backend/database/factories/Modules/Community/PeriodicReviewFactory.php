<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Community;

use App\Models\User;
use App\Modules\Community\Models\PeriodicReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PeriodicReview> */
class PeriodicReviewFactory extends Factory
{
    protected $model = PeriodicReview::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // No `workspace_id`: `BelongsToWorkspace` fills it from the current
        // context, and naming one here would file the row under a third workspace
        // inside `forWorkspace()`. See `ConversationFactory`.
        return [
            'student_user_id' => User::factory(),
            'teacher_user_id' => User::factory(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'commitment' => 4,
            'participation' => 4,
            'homework' => 3,
            'improvement' => 5,
            'note' => null,
        ];
    }

    /** A published row — `published_at` is not fillable, so it is forced. */
    public function published(): self
    {
        return $this->afterCreating(function (PeriodicReview $review): void {
            $review->forceFill(['published_at' => now()])->save();
        });
    }
}
