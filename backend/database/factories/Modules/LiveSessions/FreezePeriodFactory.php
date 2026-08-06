<?php

declare(strict_types=1);

namespace Database\Factories\Modules\LiveSessions;

use App\Models\User;
use App\Modules\LiveSessions\Models\FreezePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FreezePeriod> */
class FreezePeriodFactory extends Factory
{
    protected $model = FreezePeriod::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Workspace-wide by default: the school holiday, not the exception.
            'student_user_id' => null,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addWeek()->toDateString(),
            'reason' => 'إجازة',
            'created_by' => User::factory(),
        ];
    }

    public function forStudent(User $student): static
    {
        return $this->state(fn (): array => [
            'student_user_id' => $student->getKey(),
        ]);
    }
}
