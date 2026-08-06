<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Settlement;

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\RateRequestStatus;
use App\Modules\Settlement\Models\RateChangeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RateChangeRequest> */
class RateChangeRequestFactory extends Factory
{
    protected $model = RateChangeRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'teacher_profile_id' => TeacherProfile::factory(),
            'session_type' => ClassSessionType::Individual,
            'current_amount_minor' => 5000,
            'requested_amount_minor' => 6000,
            'currency' => 'QAR',
            'status' => RateRequestStatus::Pending,
            'requested_by' => User::factory(),
            'requested_at' => now(),
        ];
    }
}
