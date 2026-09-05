<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Models\Order;
use App\Shared\Data\DataTransferObject;

/**
 * What the buyer chose, frozen at the moment they chose it (spec 027 · FR-012 · FR-014 · FR-015).
 *
 * ⚠️ THIS IS A SNAPSHOT, NOT A POINTER. The plan can be renamed, repriced or have
 * its duration changed while a manual transfer clears, and the cohort can be
 * archived — so the staff queue must read what was BOUGHT rather than what the
 * plan says today (FR-013 · FR-014). The uuids travel too, because approval has
 * to re-resolve the cohort; the names travel because a row must stay readable
 * after the thing it names is gone.
 *
 * ⚠️ AND IT LIVES IN `orders.metadata`, NOT IN COLUMNS. `PurchaseSubscription`'s
 * own docblock states the rule: `orders` is shared by four kinds and a `plan_id`
 * on it would be null for three of them. Same for every key here.
 *
 * ⚠️ ONE READER, AND IT IS THIS CLASS. Reaching for `$order->metadata['cohort_name']`
 * from a Filament column or a Resource is the second spelling that returns `null`
 * silently at the first rename — the shape is asserted in exactly one place.
 *
 * There is deliberately no reader Action beside this: `App\Shared\Actions\Action`
 * is for business logic with one `handle()`, not for deserialisation, and the
 * repository already reads `orders.metadata` inline (`ApproveOrder`, `ActivateSubscription`).
 */
final class SubscriptionIntent extends DataTransferObject
{
    public function __construct(
        public readonly string $planUuid,
        public readonly string $planTitle,
        public readonly int $durationDays,
        public readonly string $sessionType,
        public readonly string $mode,
        public readonly ?string $cohortUuid,
        public readonly ?string $cohortName,
        public readonly ?string $teacherUuid,
        public readonly ?string $teacherName,
    ) {}

    public const MODE_COHORT = 'cohort';

    public const MODE_PRIVATE = 'private';

    /**
     * The snapshot on this order, or null when the order carries none.
     *
     * Null is the correct answer for three real cases and they are not errors:
     * an order of another kind, a subscription order written before spec 027, and
     * a row whose metadata was truncated. Every one of them renders as «—»; none
     * of them may provoke a lookup, because a Filament column runs once per row
     * and a fallback query inside one is an N+1 by construction.
     */
    public static function fromOrder(Order $order): ?self
    {
        $metadata = $order->metadata;

        if (! is_array($metadata) || ! isset($metadata['mode'], $metadata['plan_uuid'], $metadata['plan_title'])) {
            return null;
        }

        return new self(
            planUuid: (string) $metadata['plan_uuid'],
            planTitle: (string) $metadata['plan_title'],
            durationDays: (int) ($metadata['duration_days'] ?? 0),
            sessionType: (string) ($metadata['session_type'] ?? ''),
            mode: (string) $metadata['mode'],
            cohortUuid: isset($metadata['cohort_uuid']) ? (string) $metadata['cohort_uuid'] : null,
            cohortName: isset($metadata['cohort_name']) ? (string) $metadata['cohort_name'] : null,
            teacherUuid: isset($metadata['teacher_uuid']) ? (string) $metadata['teacher_uuid'] : null,
            teacherName: isset($metadata['teacher_name']) ? (string) $metadata['teacher_name'] : null,
        );
    }

    public function isCohort(): bool
    {
        return $this->mode === self::MODE_COHORT;
    }

    /**
     * What the officer reads in the «المجموعة» column (FR-017).
     *
     * A private subscription reads the words, never an empty cell or a dash — the
     * requirement says so, because a blank is indistinguishable from missing data.
     */
    public function targetLabel(): string
    {
        return $this->isCohort()
            ? ($this->cohortName ?? 'مجموعة محذوفة')
            : 'حصص خاصّة';
    }

    /** @return array<string, mixed> the shape written into `orders.metadata` */
    public function toMetadata(): array
    {
        return [
            'plan_uuid' => $this->planUuid,
            'plan_title' => $this->planTitle,
            'duration_days' => $this->durationDays,
            'session_type' => $this->sessionType,
            'mode' => $this->mode,
            'cohort_uuid' => $this->cohortUuid,
            'cohort_name' => $this->cohortName,
            'teacher_uuid' => $this->teacherUuid,
            'teacher_name' => $this->teacherName,
        ];
    }
}
