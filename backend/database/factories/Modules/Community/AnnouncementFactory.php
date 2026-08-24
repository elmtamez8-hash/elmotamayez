<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Community;

use App\Models\User;
use App\Modules\Community\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Announcement> */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // No `workspace_id`: `BelongsToWorkspace` fills it from the current
        // context, and naming one here would file the row under a third
        // workspace inside `forWorkspace()`. See `PeriodicReviewFactory`.
        return [
            'author_user_id' => User::factory(),
            'scope' => Announcement::SCOPE_ALL,
            'body' => 'حصة الغد تبدأ الساعة الخامسة بدل الرابعة.',
            'is_urgent' => false,
        ];
    }

    /** Published — `published_at` is not fillable, so it is forced. */
    public function published(): self
    {
        return $this->afterCreating(function (Announcement $announcement): void {
            $announcement->forceFill(['published_at' => now()])->save();
        });
    }
}
