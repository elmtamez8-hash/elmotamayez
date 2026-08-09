<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\BillingCadence;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\ZeroBalanceBehavior;
use App\Shared\Data\DataTransferObject;

/**
 * A requested change to a workspace's billing policy.
 *
 * Every field is nullable and null means "leave it alone" — a PATCH that sent
 * only the mode would otherwise reset the thresholds to their defaults, which is
 * a change nobody asked for and nobody would see until an alert failed to fire.
 */
class BillingSettingsData extends DataTransferObject
{
    /** @param list<int>|null $alertThresholds */
    public function __construct(
        public readonly ?BillingMode $mode = null,
        public readonly ?BillingCadence $cadence = null,
        public readonly ?ZeroBalanceBehavior $zeroBalanceBehavior = null,
        public readonly ?array $alertThresholds = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $thresholds = $data['alert_thresholds'] ?? null;

        return new self(
            mode: isset($data['mode']) ? BillingMode::from((string) $data['mode']) : null,
            cadence: isset($data['cadence']) ? BillingCadence::from((string) $data['cadence']) : null,
            zeroBalanceBehavior: isset($data['zero_balance_behavior'])
                ? ZeroBalanceBehavior::from((string) $data['zero_balance_behavior'])
                : null,
            alertThresholds: is_array($thresholds)
                ? array_values(array_map('intval', $thresholds))
                : null,
        );
    }
}
