<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Settlement;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Models\SettlementPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SettlementPeriod> */
class SettlementPeriodFactory extends Factory
{
    protected $model = SettlementPeriod::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $starts = CarbonImmutable::now()->startOfMonth();

        return [
            'teacher_profile_id' => TeacherProfile::factory(),
            'starts_on' => $starts,
            'ends_on' => $starts->addDays(29),
            'status' => SettlementPeriodStatus::Open,
            'currency' => 'QAR',
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => SettlementPeriodStatus::Closed,
            'closed_at' => now(),
        ]);
    }
}
