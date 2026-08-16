<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Models\User;
use App\Modules\Assessments\Models\Accommodation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Accommodation>
 */
class AccommodationFactory extends Factory
{
    protected $model = Accommodation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'student_user_id' => User::factory(),
            'extra_time_pct' => 50,
            'extended_days' => 2,
            'reason' => 'قرارٌ من لجنة الدعم.',
            'granted_by' => User::factory(),
            'revoked_at' => null,
        ];
    }
}
