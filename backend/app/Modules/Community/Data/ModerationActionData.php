<?php

declare(strict_types=1);

namespace App\Modules\Community\Data;

use App\Modules\Community\Enums\ModerationVerdict;
use App\Shared\Data\DataTransferObject;

/**
 * One moderation decision as the panel sends it.
 *
 * The subject travels as a uuid — a user's or a message's — and is resolved
 * inside the Action after the permission is checked, never by route binding.
 */
final class ModerationActionData extends DataTransferObject
{
    public function __construct(
        public readonly ModerationVerdict $verdict,
        public readonly string $subjectType,
        public readonly string $subjectUuid,
        public readonly ?string $reason = null,
        public readonly ?string $expiresAt = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $expires = $data['expires_at'] ?? null;

        return new self(
            verdict: ModerationVerdict::from((string) ($data['verdict'] ?? '')),
            subjectType: (string) ($data['subject_type'] ?? ''),
            subjectUuid: (string) ($data['subject_uuid'] ?? ''),
            reason: isset($data['reason']) ? trim((string) $data['reason']) : null,
            expiresAt: is_string($expires) && $expires !== '' ? $expires : null,
        );
    }
}
