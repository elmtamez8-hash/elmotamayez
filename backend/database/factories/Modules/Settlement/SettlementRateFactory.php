<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Settlement;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Models\SettlementRate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SettlementRate> */
class SettlementRateFactory extends Factory
{
    protected $model = SettlementRate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'teacher_profile_id' => TeacherProfile::factory(),
            'session_type' => ClassSessionType::Individual,
            'subject_id' => null,
            'grade_level' => null,
            // 50.00 QAR in the minor unit.
            'amount_minor' => 5000,
            'currency' => 'QAR',
            'effective_from' => CarbonImmutable::now()->subYear(),
        ];
    }

    public function group(): static
    {
        return $this->state(fn (): array => [
            'session_type' => ClassSessionType::Group,
            'amount_minor' => 2000,
        ]);
    }

    public function effectiveFrom(CarbonImmutable $moment): static
    {
        return $this->state(fn (): array => ['effective_from' => $moment]);
    }

    public function amount(int $minor): static
    {
        return $this->state(fn (): array => ['amount_minor' => $minor]);
    }
}
