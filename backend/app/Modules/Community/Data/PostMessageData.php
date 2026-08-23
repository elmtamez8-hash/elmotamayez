<?php

declare(strict_types=1);

namespace App\Modules\Community\Data;

use App\Shared\Data\DataTransferObject;

/**
 * One message, addressed by the conversation's uuid.
 *
 * ⚠️ THE UUID TRAVELS AS A STRING AND IS RESOLVED INSIDE THE ACTION, after the
 * membership check — never by route-model binding. `WorkspaceScope` is inert for
 * a student, so an implicit `{conversation}` binding resolves ANY workspace's row
 * before a policy runs. The `RedeemReward` pattern from 009.
 */
final class PostMessageData extends DataTransferObject
{
    public function __construct(
        public readonly string $conversationUuid,
        public readonly string $body,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            conversationUuid: (string) ($data['conversation'] ?? ''),
            body: trim((string) ($data['body'] ?? '')),
        );
    }
}
