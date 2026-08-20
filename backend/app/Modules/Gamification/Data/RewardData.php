<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Data;

use App\Modules\Gamification\Enums\RewardType;
use App\Shared\Data\DataTransferObject;

final class RewardData extends DataTransferObject
{
    public function __construct(
        public readonly string $title,
        public readonly int $priceCoins,
        public readonly int $stock,
        public readonly RewardType $type,
        public readonly ?int $monthlyCap,
        public readonly bool $isActive,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: (string) $data['title'],
            priceCoins: (int) $data['price_coins'],
            stock: (int) $data['stock'],
            type: RewardType::from((string) $data['reward_type']),
            monthlyCap: isset($data['monthly_cap']) ? (int) $data['monthly_cap'] : null,
            isActive: (bool) ($data['is_active'] ?? true),
        );
    }
}
