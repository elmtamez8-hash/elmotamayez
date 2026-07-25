<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Learning;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'uuid' => Str::uuid(),
            'course_id' => Course::factory(),
            'student_user_id' => User::factory(),
            'source' => 'manual',
            'order_id' => null,
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
            'completed_at' => null,
            'expires_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'progress_pct' => 100,
            'completed_at' => now(),
        ]);
    }
}
