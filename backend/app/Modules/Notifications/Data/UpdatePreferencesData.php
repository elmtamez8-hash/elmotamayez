<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Data\DataTransferObject;

final class UpdatePreferencesData extends DataTransferObject
{
    /** @param list<array{type: NotificationType, channels: list<NotificationChannel>, digest: int|null}> $preferences */
    public function __construct(
        public readonly array $preferences,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $data['preferences'] ?? [];

        return new self(array_values(array_map(
            static fn (array $row): array => [
                'type' => NotificationType::from((string) $row['type']),
                'channels' => array_values(array_filter(array_map(
                    static fn (string $value): ?NotificationChannel => NotificationChannel::tryFrom($value),
                    /** @var list<string> */
                    $row['channels'] ?? [],
                ))),
                'digest' => isset($row['digest_window_minutes']) ? (int) $row['digest_window_minutes'] : null,
            ],
            $rows,
        )));
    }
}
