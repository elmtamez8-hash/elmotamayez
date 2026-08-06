<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Settlement;

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeacherPayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeacherPayout> */
class TeacherPayoutFactory extends Factory
{
    protected $model = TeacherPayout::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'teacher_profile_id' => TeacherProfile::factory(),
            'settlement_period_id' => SettlementPeriod::factory(),
            'amount_minor' => 50000,
            'currency' => 'QAR',
            'reference' => fake()->bothify('TRF-####-????'),
            'method' => 'bank_transfer',
            'executed_at' => now(),
            'executed_by' => User::factory(),
        ];
    }
}
