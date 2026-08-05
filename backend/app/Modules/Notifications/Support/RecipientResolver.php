<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Models\User;
use App\Shared\Contracts\GuardianDirectory;
use Illuminate\Support\Collection;

/**
 * Who hears about an event: the person it happened to, plus any guardian
 * authorised for that kind of news (FR-021).
 *
 * Guardians are reached through GuardianDirectory rather than through Identity's
 * models, so this module does not need to know that a relations table exists
 * (Constitution III).
 */
class RecipientResolver
{
    public function __construct(
        private readonly GuardianDirectory $guardians,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function resolve(User $primary, NotificationType $type, ?User $subject = null): Collection
    {
        /** @var Collection<int, User> $recipients */
        $recipients = collect([$primary]);

        $permission = $type->requiredGuardianPermission();
        $student = $subject ?? $primary;

        if ($type->targetsGuardians() && $permission !== null) {
            $recipients = $recipients->merge($this->guardians->authorisedGuardians($student, $permission));
        }

        // One person, one notification — even if they are somehow both the
        // subject and their own guardian.
        return $recipients->unique(static fn (User $user): int => (int) $user->getKey())->values();
    }
}
