<?php

declare(strict_types=1);

namespace App\Modules\Community\Data;

use App\Modules\Community\Models\Announcement;
use App\Shared\Data\DataTransferObject;

final class AnnouncementData extends DataTransferObject
{
    public function __construct(
        public readonly string $body,
        public readonly string $scope,
        /** A course or class-session uuid, and null for `all`. Resolved to an id
         * INSIDE the Action, after the workspace check — a bare id in a request
         * body is an identity probe (NFR-001أ) and this one would address another
         * teacher's course. */
        public readonly ?string $scopeUuid = null,
        public readonly bool $isUrgent = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            body: (string) $data['body'],
            scope: (string) ($data['scope'] ?? Announcement::SCOPE_ALL),
            scopeUuid: isset($data['scope_uuid']) ? (string) $data['scope_uuid'] : null,
            isUrgent: (bool) ($data['is_urgent'] ?? false),
        );
    }
}
