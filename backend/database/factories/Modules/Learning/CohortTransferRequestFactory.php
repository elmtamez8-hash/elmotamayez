<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Learning;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortTransferRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CohortTransferRequest>
 */
class CohortTransferRequestFactory extends Factory
{
    protected $model = CohortTransferRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'uuid' => Str::uuid(),
            'course_id' => Course::factory(),
            'student_user_id' => User::factory(),
            'to_cohort_id' => Cohort::factory(),
            'from_cohort_id' => null,
            'student_reason' => null,
            'status' => CohortTransferRequest::PENDING,
            'decided_by' => null,
            'decided_at' => null,
            'decision_reason' => null,
            'pending_slot' => 0,
        ];
    }
}
