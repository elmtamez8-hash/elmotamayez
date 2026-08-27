<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Learning;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CohortMembership>
 */
class CohortMembershipFactory extends Factory
{
    protected $model = CohortMembership::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'uuid' => Str::uuid(),
            'cohort_id' => Cohort::factory(),
            'course_id' => Course::factory(),
            'student_user_id' => User::factory(),
            'joined_at' => now(),
            'closed_at' => null,
            'closed_slot' => 0,
        ];
    }

    /**
     * ⚠️ THE SLOT IS THE ROW'S OWN ID, so a closed state written by a factory
     * has to be written the way the Action writes it — a fixture that closes a
     * membership while leaving `closed_slot` at zero collides with the next open
     * one on the unique index, and the test fails somewhere else entirely.
     */
    public function closed(): static
    {
        return $this->afterCreating(function (CohortMembership $membership): void {
            $membership->forceFill([
                'closed_at' => now(),
                'closed_slot' => $membership->getKey(),
            ])->save();
        });
    }
}
