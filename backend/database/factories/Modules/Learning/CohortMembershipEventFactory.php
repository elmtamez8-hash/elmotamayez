<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Learning;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembershipEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CohortMembershipEvent>
 */
class CohortMembershipEventFactory extends Factory
{
    protected $model = CohortMembershipEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'uuid' => Str::uuid(),
            'course_id' => Course::factory(),
            'student_user_id' => User::factory(),
            'cohort_id' => Cohort::factory(),
            'from_cohort_id' => null,
            'event' => CohortMembershipEvent::JOINED,
            'actor_user_id' => User::factory(),
            'reason' => null,
            'created_at' => now(),
        ];
    }
}
