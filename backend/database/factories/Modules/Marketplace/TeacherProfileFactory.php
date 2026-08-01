<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Marketplace;

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeacherProfile> */
class TeacherProfileFactory extends Factory
{
    protected $model = TeacherProfile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'headline' => 'مدرّس '.fake()->randomElement(['رياضيات', 'فيزياء', 'لغة عربية']),
            'bio' => fake()->paragraph(),
            'qualifications' => ['بكالوريوس تربية'],
            'years_experience' => fake()->numberBetween(1, 20),
            'teaching_languages' => ['ar'],
            'hourly_rate' => fake()->randomFloat(2, 50, 300),
            'currency' => 'QAR',
            'is_verified' => false,
            // Unpublished by default: a factory must not be the thing that leaks a
            // teacher into the public marketplace. Opt in with ->published().
            'approval_status' => TeacherProfile::STATUS_PENDING,
            'is_publicly_listed' => false,
        ];
    }

    public function published(): self
    {
        return $this->state([
            'approval_status' => TeacherProfile::STATUS_APPROVED,
            'is_publicly_listed' => true,
        ]);
    }

    public function suspended(): self
    {
        return $this->state([
            'approval_status' => TeacherProfile::STATUS_SUSPENDED,
            'is_publicly_listed' => false,
        ]);
    }

    /** Past the minimum sessions/reviews, so a numeric trust score is shown. */
    public function scored(int $score = 86): self
    {
        return $this->state([
            'trust_score' => $score,
            'trust_score_factors' => [
                'student_rating' => 94,
                'punctuality' => 88,
                'completion' => 91,
                'tenure' => 100,
                'complaints_penalty' => 0,
            ],
            'trust_score_calculated_at' => now(),
            'completed_sessions_count' => 40,
            'students_taught_count' => 22,
            'reviews_count' => 12,
            'average_rating' => 4.7,
            'response_rate' => 95,
            'attendance_rate' => 98,
            'first_session_at' => now()->subYear(),
        ]);
    }
}
