<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionStat>
 */
class QuestionStatFactory extends Factory
{
    protected $model = QuestionStat::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'question_id' => Question::factory(),
            'attempts_count' => 10,
            'wrong_count' => 4,
            'wrong_pct' => 40.0,
            'computed_at' => now(),
        ];
    }

    /** A sample too small to state a rate for (FR-013). */
    public function insufficient(): self
    {
        return $this->state(fn (): array => [
            'attempts_count' => 2,
            'wrong_count' => 1,
            'wrong_pct' => null,
        ]);
    }
}
