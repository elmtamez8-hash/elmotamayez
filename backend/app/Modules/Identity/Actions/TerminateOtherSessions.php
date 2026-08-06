<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\SessionEndReason;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\Builder;

/**
 * Signs every other device out, and says why (FR-031).
 *
 * Changing a password or a second factor is what someone does after suspecting
 * the account is compromised. Leaving the other sessions alive means the change
 * accomplished nothing: whoever was already signed in stays signed in.
 *
 * The current token is kept, so the person making the change is not thrown out
 * of the screen they made it on.
 */
class TerminateOtherSessions extends Action
{
    public function __construct(
        private readonly TerminateAuthSession $terminate,
        private readonly DispatchNotification $notify,
    ) {}

    public function handle(User $user, SessionEndReason $reason, string $event, ?int $keepTokenId = null): void
    {
        AuthSession::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->when(
                $keepTokenId !== null,
                fn (Builder $query) => $query->where(
                    fn (Builder $inner) => $inner->whereNull('token_id')->orWhere('token_id', '!=', $keepTokenId),
                ),
            )
            ->get()
            ->each(fn (AuthSession $session) => $this->terminate->handle($session, $reason));

        // Mandatory type: it cannot be switched off, deferred or digested. A
        // security change the account holder did not make is the one message that
        // has to arrive at 3am if it happens at 3am.
        $this->notify->handle(new NotificationRequest(
            recipient: $user,
            type: NotificationType::SecurityAlert,
            variables: ['name' => $user->name, 'event' => $event],
        ));
    }
}
