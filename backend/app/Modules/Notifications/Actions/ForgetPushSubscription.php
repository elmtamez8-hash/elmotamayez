<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Models\User;
use App\Modules\Notifications\Channels\WebPushChannel;
use App\Modules\Notifications\Models\PushSubscription;
use App\Shared\Actions\Action;

/**
 * The browser says it has unsubscribed; forget the row.
 *
 * ⚠️ ADDRESSED BY `endpoint` AND CONSTRAINED BY `user_id`, never by a uuid. A
 * route that took a subscription uuid would be asking the client «which row?» —
 * a question only the server may answer, and one an authenticated attacker would
 * answer with somebody else's. Constrained this way, the worst a caller can do is
 * delete a device they already control.
 *
 * There is no third door. The other deletion is `410 Gone` from the push service
 * itself, inside {@see WebPushChannel}.
 */
class ForgetPushSubscription extends Action
{
    public function handle(User $user, string $endpoint): void
    {
        PushSubscription::query()
            ->where('user_id', $user->getKey())
            ->where('endpoint_hash', PushSubscription::hashOf($endpoint))
            ->delete();

        /*
        | The preference rows deliberately stay. They are a statement about which
        | subjects matter, not about one phone — a person who changes handset
        | should not have to re-tick anything, and a person on their last device
        | keeps a mute they set on purpose. With no subscription left, `canReach()`
        | answers false and every push delivery is recorded SKIPPED, so the rows
        | cost nothing while they wait.
        */
    }
}
