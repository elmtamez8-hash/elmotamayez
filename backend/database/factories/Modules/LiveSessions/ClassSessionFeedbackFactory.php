<?php

declare(strict_types=1);

namespace Database\Factories\Modules\LiveSessions;

use App\Models\User;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\ClassSessionFeedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClassSessionFeedback> */
class ClassSessionFeedbackFactory extends Factory
{
    protected $model = ClassSessionFeedback::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'class_session_id' => ClassSession::factory(),
            'student_user_id' => User::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'note' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
