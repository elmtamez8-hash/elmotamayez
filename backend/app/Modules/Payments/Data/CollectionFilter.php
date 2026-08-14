<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Shared\Data\DataTransferObject;
use Carbon\CarbonImmutable;

/**
 * The period and the narrowing, resolved once and passed to both readers.
 *
 * ⚠️ THE WINDOW IS HALF-OPEN — `[from, to)` — AND `to` IS ALREADY THE START OF
 * THE DAY AFTER the date the caller typed. `created_at` is a timestamp and the
 * bound is a date, so `<= '2026-08-31'` binds midnight and silently drops every
 * payment taken on the closing day: the same boundary that cost
 * `FreezePeriod::covering()` a fix in 005 and a settlement close its last day in
 * 014. The conversion happens once, here, rather than in each of the two places
 * that build a query — a second conversion is a second chance to write `<=`.
 *
 * ⚠️ AND NEVER `whereDate()`. A function wrapping the column costs it the index
 * the migration declared for exactly this report (`created_at, status, method`),
 * which is the whole of `SC-014`.
 */
final class CollectionFilter extends DataTransferObject
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?PaymentMethod $method = null,
        public readonly ?PaymentStatus $status = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $from = CarbonImmutable::parse((string) $data['from'])->startOfDay();

        // Start of the day AFTER the one asked for. See the class note.
        $to = CarbonImmutable::parse((string) $data['to'])->startOfDay()->addDay();

        $method = $data['method'] ?? null;
        $status = $data['status'] ?? null;

        return new self(
            from: $from,
            to: $to,
            method: is_string($method) ? PaymentMethod::from($method) : null,
            status: is_string($status) ? PaymentStatus::from($status) : null,
        );
    }
}
