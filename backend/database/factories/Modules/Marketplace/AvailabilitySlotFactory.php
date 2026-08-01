<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Marketplace;

use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AvailabilitySlot> */
class AvailabilitySlotFactory extends Factory
{
    protected $model = AvailabilitySlot::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $startHour = fake()->numberBetween(8, 18);

        return [
            'teacher_profile_id' => TeacherProfile::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'start_time' => sprintf('%02d:00:00', $startHour),
            'end_time' => sprintf('%02d:00:00', $startHour + 2),
        ];
    }
}
