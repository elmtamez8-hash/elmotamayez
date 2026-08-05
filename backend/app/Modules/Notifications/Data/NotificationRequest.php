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
    ) {}
}
