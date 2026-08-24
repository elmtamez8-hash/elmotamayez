<?php

declare(strict_types=1);

namespace App\Modules\Community\Data;

use App\Shared\Data\DataTransferObject;
use Carbon\CarbonImmutable;

class GradingSchemeData extends DataTransferObject
{
    /** @param array<string, int> $weights */
    public function __construct(
        public readonly ?string $courseUuid,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly array $weights,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array<string, int> $weights */
        $weights = array_map(
            static fn (mixed $value): int => (int) $value,
            (array) ($data['weights'] ?? [])
        );

        return new self(
            courseUuid: isset($data['course_uuid']) ? (string) $data['course_uuid'] : null,
            periodStart: CarbonImmutable::parse((string) $data['period_start']),
            periodEnd: CarbonImmutable::parse((string) $data['period_end']),
            weights: $weights,
        );
    }
}
