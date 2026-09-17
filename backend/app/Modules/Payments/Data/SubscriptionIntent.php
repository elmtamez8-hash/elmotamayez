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
 * ⛔ AND THERE IS DELIBERATELY NO «EXACTLY ONE OF THE TWO SHAPES» GUARD IN THE
 * CONSTRUCTOR (٠٣٦ · T029), tempting as one looks now that `durationDays` and
 * `sessionCount` are both nullable. Four reasons, and any one of them is enough:
 *
 * - The properties are readonly, so there is no second construction path a guard
 *   would be protecting — {@see self::fromOrder()} goes through this same
 *   constructor.
 * - Which means the guard would fire inside a DESERIALISER, called from eight
 *   places, three of them AFTER the money has committed. A throw there turns a
 *   malformed old row into a failed job on an approved payment.
 * - It would outlaw three shapes this class's own docblock calls legitimate: a
 *   truncated metadata blob, an order written before ٠٢٧, and an order of
 *   another kind entirely.
 * - And it would cover nothing. The branch in `PurchaseSubscription` is what
 *   decides the shape, and it runs BEFORE any order exists — so a refusal there
 *   refuses a purchase, which is a guard, while a refusal here refuses to read
 *   one that has already been paid for.
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
        public readonly ?int $durationDays,
        public readonly ?int $sessionCount,
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
            durationDays: self::positive($metadata['duration_days'] ?? null),
            sessionCount: self::positive($metadata['session_count'] ?? null),
            sessionType: (string) ($metadata['session_type'] ?? ''),
            mode: (string) $metadata['mode'],
            cohortUuid: isset($metadata['cohort_uuid']) ? (string) $metadata['cohort_uuid'] : null,
            cohortName: isset($metadata['cohort_name']) ? (string) $metadata['cohort_name'] : null,
            teacherUuid: isset($metadata['teacher_uuid']) ? (string) $metadata['teacher_uuid'] : null,
            teacherName: isset($metadata['teacher_name']) ? (string) $metadata['teacher_name'] : null,
        );
    }

    /**
     * A plan bought by the HOUR rather than by the month (٠٣٦ · FR-020).
     *
     * ⚠️ ASKED OF THE SNAPSHOT, NEVER OF THE PLAN ROW. A teacher may change a
     * plan's shape while a manual transfer clears, and everything else about
     * this order — its price, its duration, its group — is already read from
     * what was bought rather than from what the plan says today.
     */
    public function isSessionShaped(): bool
    {
        return $this->sessionCount !== null;
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

    /**
     * A positive whole number, or `null` for everything else.
     *
     * ⚠️ ZERO AND A NEGATIVE ARE READ AS «ABSENT», NOT AS A VALUE. A duration of
     * zero is what the old `?? 0` produced for a truncated row, and it travels
     * all the way to `addDays(0)` — a subscription that expires the instant it
     * is activated, after the student has paid, with no error anywhere. The
     * shape of this snapshot is «one of the two, or neither», and neither is a
     * case the reader has to be able to tell from a malformed zero.
     */
    private static function positive(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return array<string, mixed> the shape written into `orders.metadata` */
    public function toMetadata(): array
    {
        return [
            'plan_uuid' => $this->planUuid,
            'plan_title' => $this->planTitle,
            'duration_days' => $this->durationDays,
            'session_count' => $this->sessionCount,
            'session_type' => $this->sessionType,
            'mode' => $this->mode,
            'cohort_uuid' => $this->cohortUuid,
            'cohort_name' => $this->cohortName,
            'teacher_uuid' => $this->teacherUuid,
            'teacher_name' => $this->teacherName,
        ];
    }
}
