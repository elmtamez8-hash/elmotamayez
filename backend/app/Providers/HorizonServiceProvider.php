<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        /*
        | ⚠️ A QUEUE NOBODY IS TOLD ABOUT IS A QUEUE THAT FAILS QUIETLY. Spec 007
        | put the payment sweep, the callback processing and the charge listener
        | on the queue, so a stuck worker now means money that settled and was
        | never credited — the exact condition the sweep exists to catch, unable
        | to run.
        |
        | The address is read from the environment and defaults to nothing: an
        | operator's mailbox is not a fact about the code, and a hardcoded one is
        | a credential in the repository. Left unset, Horizon simply does not
        | notify — which is where this started, but now visibly rather than by
        | omission.
        |
        | Mail only. The other two channels stay commented because neither is
        | implemented: a Slack webhook is a secret nobody has issued, and SMS
        | goes through a provider this platform has not chosen. A route to an
        | unconfigured channel throws inside the notifier and takes the alert
        | with it.
        */
        $recipient = config('horizon.notification_email');

        if (is_string($recipient) && $recipient !== '') {
            Horizon::routeMailNotificationsTo($recipient);
        }
    }

    /**
     * Who may open `/horizon` outside local.
     *
     * ⚠️ THIS COMPARED AGAINST AN EMPTY ARRAY, so the answer was always no and
     * the dashboard was unreachable in every environment but local — with the
     * queue running the platform's money. The gate is the SUPER ADMIN FLAG, not
     * a list of addresses: an address list is a deploy away from the person who
     * needs it, and it survives the day that person leaves.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => $user?->is_super_admin === true);
    }
}
