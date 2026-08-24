<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Community;

use App\Models\User;
use App\Modules\Community\Models\ReportCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportCard> */
class ReportCardFactory extends Factory
{
    protected $model = ReportCard::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'student_user_id' => User::factory(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'generated_at' => now(),
        ];
    }

    /** Published, with totals — none of the three is fillable, so all are forced. */
    public function published(?float $overall = 80.0): self
    {
        return $this->afterCreating(function (ReportCard $card) use ($overall): void {
            $card->forceFill([
                'published_at' => now(),
                'overall_pct' => $overall,
            ])->save();
        });
    }
}
