<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Filament\Resources\OrderResource;
use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Events\ReceiptUploaded;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Models\PlatformStaff;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Collection;

/**
 * Tells the people who decide a receipt that one is waiting.
 *
 * `ReceiptUploaded` has fired since spec 007 and nothing listened to it, so a
 * transfer sat in `/admin` until somebody happened to open the orders screen —
 * while the payer read «قيد المراجعة» with nobody on the other end of it.
 *
 * ⚠️ THE RECIPIENTS ARE WHOEVER `OrderPolicy::approve()` WOULD ADMIT, derived
 * from the same two permissions rather than from a role NAME. A course order is
 * decided under `PAYMENTS_APPROVE`; every platform sale (credits, store,
 * subscription) under `BILLING_PURCHASE_APPROVE` — the split `OrderPolicy`
 * keeps on purpose. Platform standing lives in `platform_staff`, whose role
 * names are resolved to the teamless spatie roles holding the permission, so a
 * custom platform role granted it tomorrow is reached without this file moving.
 *
 * ⚠️ SUPER ADMINS ARE THE FALLBACK, NOT THE DEFAULT. `Gate::before` lets them
 * decide anything, but pinging the platform operator about every transfer when a
 * finance officer exists is how the bell gets ignored. With nobody holding the
 * permission, the queue must still reach a human — so they are told then.
 *
 * ⚠️ NO AMOUNT, for the reason `NotifyPaymentOutcome` gives, and because the
 * amount is on the screen where the decision is taken.
 */
class NotifyReceiptAwaitingReview implements ShouldQueueAfterCommit
{
    public function __construct(private readonly DispatchNotification $dispatch) {}

    public function handle(ReceiptUploaded $event): void
    {
        $order = $event->order;

        $payer = $order->user;
        $what = $order->course?->title;

        foreach ($this->deciders($order) as $officer) {
            // The officer who uploaded it on the payer's behalf (024 · FR-007)
            // is already looking at it.
            if ($officer->is($event->uploader)) {
                continue;
            }

            $this->dispatch->handle(new NotificationRequest(
                recipient: $officer,
                type: NotificationType::ReceiptAwaitingReview,
                variables: [
                    'payer' => $payer->name !== '' ? $payer->name : $payer->email,
                    'order' => is_string($what) && $what !== '' ? $what : $order->kind->label(),
                ],
                /*
                | ⚠️ THE ORDER'S OWN PAGE IN `/admin`, NOT `/orders`. The
                | approve and refuse buttons left the frontend list when the
                | decision moved to the panel, so `/orders` sent the officer to a
                | screen with nothing to press. The edit page carries both as
                | header actions. Relative, because the bell reaches it through
                | the panel handoff (`openAdminPanel(to)`), which accepts a path
                | under the panel and nothing else.
                */
                actionUrl: OrderResource::getUrl('edit', ['record' => $order], isAbsolute: false, panel: 'admin'),
                sourceType: Order::class,
                sourceId: (int) $order->getKey(),
            ));
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function deciders(Order $order): Collection
    {
        $permission = $order->requiresPlatformApproval()
            ? Permissions::BILLING_PURCHASE_APPROVE
            : Permissions::PAYMENTS_APPROVE;

        // The teamless roles only: a workspace's own role with the same name
        // must not lend its members a platform queue (see PlatformStaffDirectory).
        $roles = Role::query()
            ->withoutTeamScope()
            ->whereNull('team_id')
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->pluck('name');

        $staff = $roles->isEmpty()
            ? collect()
            : User::query()
                ->whereIn('id', PlatformStaff::query()->whereIn('role', $roles)->select('user_id'))
                ->get();

        if ($staff->isNotEmpty()) {
            return $staff;
        }

        return User::query()->where('is_super_admin', true)->get();
    }
}
