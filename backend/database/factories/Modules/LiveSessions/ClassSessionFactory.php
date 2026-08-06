<?php

declare(strict_types=1);

namespace Database\Factories\Modules\LiveSessions;

use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClassSession> */
class ClassSessionFactory extends Factory
{
    protected $model = ClassSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::now()->addDays(3)->startOfHour();
        $duration = 60;

        return [
            'teacher_profile_id' => TeacherProfile::factory(),
            'title' => fake()->sentence(3),
            'type' => ClassSessionType::Group,
            'status' => ClassSessionStatus::Scheduled,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($duration),
            'duration_minutes' => $duration,
            'seats_total' => 8,
            'seats_taken' => 0,
        ];
    }

    public function individual(): static
    {
        return $this->state(fn (): array => [
            'type' => ClassSessionType::Individual,
            'seats_total' => 1,
        ]);
    }

    /** Starting now, so presence and the ladder can be exercised. */
    public function live(): static
    {
        $startsAt = CarbonImmutable::now();

        return $this->state(fn (array $attributes): array => [
            'status' => ClassSessionStatus::Live,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes((int) ($attributes['duration_minutes'] ?? 60)),
            'room_opened_at' => $startsAt,
        ]);
    }

    public function past(): static
    {
        $startsAt = CarbonImmutable::now()->subDays(2);

        return $this->state(fn (array $attributes): array => [
            'status' => ClassSessionStatus::Completed,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes((int) ($attributes['duration_minutes'] ?? 60)),
            'delivered_at' => $startsAt->addMinutes(60),
        ]);
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes): array => [
            'seats_taken' => (int) ($attributes['seats_total'] ?? 8),
        ]);
    }
}
