<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Models\UnlockRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnlockRule>
 */
class UnlockRuleFactory extends Factory
{
    protected $model = UnlockRule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            // The workspace default unless a caller says otherwise. `0`, never
            // null: see the model.
            'course_id' => UnlockRule::DEFAULT_SCOPE,
            'requires_attendance' => true,
            'requires_assignment' => true,
            'min_score_pct' => 50,
            'is_active' => true,
            'updated_by' => null,
        ];
    }

    public function forCourse(int $courseId): self
    {
        return $this->state(fn (): array => ['course_id' => $courseId]);
    }
}
