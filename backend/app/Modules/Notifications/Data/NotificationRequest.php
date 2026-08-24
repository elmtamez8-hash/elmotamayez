<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

use App\Models\User;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Data\DataTransferObject;

/**
 * What business logic asks for: "tell this person about this".
 *
 * Note what is absent: any mention of a channel or a provider. Choosing those is
 * the architecture's job, decided from the recipient's preferences — that
 * absence is the addendum's constraint, and ProviderAgnosticTest fails the build
 * if it reappears anywhere under Actions/.
 */
final class NotificationRequest extends DataTransferObject
{
    /** @param array<string, mixed> $variables */
    public function __construct(
        public readonly User $recipient,
        public readonly NotificationType $type,
        public readonly array $variables = [],
        public readonly ?string $actionUrl = null,
        /** The student the event concerns, when that is someone other than the
         * recipient. Drives guardian resolution and lets a parent of three tell
         * which child a message is about. */
        public readonly ?User $subject = null,
        public readonly ?int $workspaceId = null,
        /*
        | What produced this, when something did (010 · FR-046).
        |
        | ⚠️ A KEY, NEVER A COUNT. «How many were told, and how many read it» has
        | to be true at the moment it is read: a stored counter drifts the first
        | time a notification is deleted and then reports more readers than there
        | were recipients, permanently. This pair is what makes counting live
        | cheap — without it the count is `JSON_EXTRACT(payload, …)`, a function
        | around a column on the fastest-growing table in the product, in a list.
        |
        | Null for the overwhelming majority: a source is what a fan-out has and
        | a single addressed message does not.
        */
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,
    ) {}
}
