<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttemptItem>
 */
class AttemptItemFactory extends Factory
{
    protected $model = AttemptItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            // No Attempt::factory() default:  has no factory, and an
            // attempt item without a real attempt is a snapshot of nothing. The
            // caller supplies it, which every honest use of this factory does.
            'attempt_id' => null,
            'question_id' => Question::factory(),
            'order' => 0,
            'points' => 1,
            'snapshot' => [
                'type' => 'mcq',
                'content' => fake()->sentence().'?',
                'options' => [],
                'correct_option_ids' => [],
            ],
        ];
    }
}
