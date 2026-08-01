<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Marketplace;

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeacherApplication> */
class TeacherApplicationFactory extends Factory
{
    protected $model = TeacherApplication::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => TeacherApplication::STATUS_DRAFT,
            'current_step' => 1,
            'step_data' => null,
        ];
    }

    /** Every step answered, ready to submit. */
    public function complete(): self
    {
        return $this->state([
            'current_step' => TeacherApplication::LAST_STEP,
            'step_data' => [
                'step_2' => [
                    'subjects' => ['math'],
                    'grade_levels' => ['secondary'],
                    'years_experience' => 8,
                    'qualifications' => ['بكالوريوس رياضيات'],
                    'teaching_languages' => ['ar'],
                    'headline' => 'مدرّس رياضيات',
                    'bio' => null,
                ],
                'step_3' => ['documents_acknowledged' => true],
                'step_4' => [
                    'hourly_rate' => '120.00',
                    'currency' => 'QAR',
                    'availability' => [
                        ['day_of_week' => 0, 'start_time' => '16:00:00', 'end_time' => '18:00:00'],
                    ],
                ],
            ],
        ]);
    }

    public function submitted(): self
    {
        return $this->complete()->state([
            'status' => TeacherApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
    }
}
