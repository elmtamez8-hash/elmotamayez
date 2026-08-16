<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'assignment_id' => Assignment::factory(),
            'student_user_id' => User::factory(),
            // The state a row starts in when the sweep wrote it: nothing handed
            // in, and the deadline gone.
            'state' => Submission::STATE_MISSED,
            'answer_text' => null,
            'submitted_at' => null,
            'late_by_minutes' => 0,
            'late_penalty_applied_pct' => 0,
            'extension_until' => null,
            'score' => null,
            'feedback' => null,
            'graded_at' => null,
            'graded_by' => null,
        ];
    }

    public function onTime(): self
    {
        return $this->state(fn (): array => [
            'state' => Submission::STATE_ON_TIME,
            'answer_text' => 'إجابتي.',
            'submitted_at' => now(),
        ]);
    }
}
