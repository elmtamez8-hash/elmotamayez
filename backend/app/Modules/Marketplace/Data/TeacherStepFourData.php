<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Data;

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
        $slots = [];

        foreach ((array) ($data['availability'] ?? []) as $slot) {
            $slot = (array) $slot;

            $slots[] = [
                'day_of_week' => (int) $slot['day_of_week'],
                'start_time' => self::seconds((string) $slot['start_time']),
                'end_time' => self::seconds((string) $slot['end_time']),
            ];
        }

        return new self(
            hourlyRate: number_format((float) $data['hourly_rate'], 2, '.', ''),
            currency: strtoupper((string) ($data['currency'] ?? 'QAR')),
            availability: $slots,
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

    /** "16:00" and "16:00:00" must compare equal, so everything is stored H:i:s. */
    private static function seconds(string $time): string
    {
        return substr_count($time, ':') === 1 ? $time.':00' : $time;
    }
}
