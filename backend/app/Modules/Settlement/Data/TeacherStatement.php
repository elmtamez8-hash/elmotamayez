<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Data;

use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementRate;
use App\Shared\Data\DataTransferObject;
use Illuminate\Support\Collection;

/**
 * One teacher's window: how much work, at what price, for how much.
 *
 * The shape exists as a DTO rather than an array because two surfaces read it —
 * the API resource and the CSV export — and FR-021 requires them to carry the
 * same data. A shape that lives in the resource would be a shape the export
 * rebuilds from the query, which is how the two drift apart.
 *
 * Every field here is the teacher's side of the transaction. There is nothing
 * about a student's payment, and nothing to filter out on the way to a screen:
 * `TeacherFieldAllowlist` asserts that from the outside (FR-018 · SC-007).
 */
final class TeacherStatement extends DataTransferObject
{
    /**
     * @param  array<string, int>  $unitCounts  status value => count
     * @param  array<string, int>  $unitsByType  session type value => count
     * @param  Collection<int, SettlementRate>  $rates
     * @param  list<array{type: string, type_label: string, reason: string|null, amount_minor: int}>  $deductions
     */
    public function __construct(
        /** Null until the period row exists — periods are created at close (US4). */
        public readonly ?string $periodUuid,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly SettlementPeriodStatus $status,
        public readonly int $studentsCount,
        public readonly array $unitCounts,
        public readonly array $unitsByType,
        public readonly Collection $rates,
        public readonly ?RateChangeRequest $pendingRateRequest,
        public readonly int $grossMinor,
        public readonly array $deductions,
        public readonly int $netMinor,
        public readonly int $carriedInMinor,
        public readonly string $currency,
    ) {}
}
