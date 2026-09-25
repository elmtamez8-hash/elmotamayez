<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Data;

use App\Modules\Marketplace\Support\AvailabilityRules;
use App\Shared\Data\DataTransferObject;
use App\Shared\Support\UserClock;

/**
 * Step 4 — price and weekly availability: wall-clock hours on the teacher's own
 * clock, named by `timezone` (2026-09-25; they were UTC before).
 */
class TeacherStepFourData extends DataTransferObject
{
    /** @param list<array{day_of_week: int, start_time: string, end_time: string}> $availability */
    public function __construct(
        public readonly string $hourlyRate,
        public readonly string $currency,
        public readonly array $availability,
        public readonly string $timezone,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            hourlyRate: number_format((float) $data['hourly_rate'], 2, '.', ''),
            currency: strtoupper((string) ($data['currency'] ?? 'QAR')),
            // التسويةُ في {@see AvailabilityRules} لا هنا: البابُ الآخرُ
            // (`PUT /teacher/availability`) يكتبُ الصفوفَ نفسَها، ونسختانِ من
            // قاعدةِ التسويةِ تفترقانِ عندَ أوّلِ تعديل.
            availability: AvailabilityRules::normalise((array) ($data['availability'] ?? [])),
            // A draft saved before the zone existed was converted and stamped by
            // the 2026-09-25 migration; the platform zone is the last resort.
            timezone: is_string($data['timezone'] ?? null) && UserClock::isValid($data['timezone'])
                ? $data['timezone']
                : UserClock::platformZone(),
        );
    }

    /**
     * Snake_case shape for step_data — see TeacherStepTwoData::toStepData().
     *
     * @return array<string, mixed>
     */
    public function toStepData(): array
    {
        return [
            'hourly_rate' => $this->hourlyRate,
            'currency' => $this->currency,
            'availability' => $this->availability,
            'timezone' => $this->timezone,
        ];
    }
}
