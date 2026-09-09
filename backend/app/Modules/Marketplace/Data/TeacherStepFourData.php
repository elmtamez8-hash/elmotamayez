<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Data;

use App\Modules\Marketplace\Support\AvailabilityRules;
use App\Shared\Data\DataTransferObject;

/** Step 4 — price and weekly availability. Times are UTC. */
class TeacherStepFourData extends DataTransferObject
{
    /** @param list<array{day_of_week: int, start_time: string, end_time: string}> $availability */
    public function __construct(
        public readonly string $hourlyRate,
        public readonly string $currency,
        public readonly array $availability,
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
        ];
    }
}
