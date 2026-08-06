<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Settlement;

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\SettlementBasis;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\TeachingUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeachingUnit> */
class TeachingUnitFactory extends Factory
{
    protected $model = TeachingUnit::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'student_user_id' => User::factory(),
            'teacher_profile_id' => TeacherProfile::factory(),
            'class_session_id' => ClassSession::factory(),
            'session_type' => ClassSessionType::Individual,
            'amount_minor' => 5000,
            'currency' => 'QAR',
            'frozen_seats' => 1,
            'basis' => SettlementBasis::FrozenSeat,
            'status' => TeachingUnitStatus::Accrued,
            'delivered_at' => now(),
            'accrued_at' => now(),
            'reversal_of_id' => TeachingUnit::NOT_A_REVERSAL,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => TeachingUnitStatus::PendingPackage,
            'pending_reason' => 'التسجيل لم يصل بعد.',
            'accrued_at' => null,
        ]);
    }

    public function disputed(): static
    {
        return $this->state(fn (): array => ['status' => TeachingUnitStatus::Disputed]);
    }
}
