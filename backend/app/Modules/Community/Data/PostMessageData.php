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
        /**
         * The uuid of an already-uploaded asset, or null (`FR-060`).
         *
         * ⚠️ AN ASSET UUID AND NOT A FILE. The bytes went straight to the
         * provider through the upload ticket, so this request stays a small JSON
         * body — a multipart send would put every picture through PHP twice and
         * make the message wait for the transfer instead of for the composer.
         */
        public readonly ?string $attachmentUuid = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $attachment = $data['attachment'] ?? null;

        return new self(
            conversationUuid: (string) ($data['conversation'] ?? ''),
            body: trim((string) ($data['body'] ?? '')),
            attachmentUuid: is_string($attachment) && $attachment !== '' ? $attachment : null,
        );
    }
}
