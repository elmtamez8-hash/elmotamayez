<?php

declare(strict_types=1);

namespace App\Modules\Store\Policies;

use App\Models\User;
use App\Modules\Store\Models\Shipment;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Who may work the fulfilment queue (spec 011 · US1 · FR-008).
 *
 * ⚠️ `STORE_SHIPMENTS_MANAGE` IS A SEPARATE PERMISSION FROM `STORE_ITEMS_MANAGE`
 * ON PURPOSE. Pricing the goods and packing them are two jobs, and this is the
 * one a teacher delegates: an assistant who posts the parcels reads a name, a
 * phone number and a home address, and holds no reason to touch the shelf price.
 * One permission for both would mean delegating the packing delegates the
 * pricing.
 *
 * ⚠️ AND THE BUYER IS NOT HERE. They read their own parcel's state through their
 * own purchase, where ownership is `buyer_user_id` — which is ownership rather
 * than permission, and therefore not a 403.
 */
class ShipmentPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::STORE_SHIPMENTS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, Shipment $shipment): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($shipment))->denied()) {
            return $workspaceCheck;
        }

        return $this->viewAny($user);
    }

    public function update(User $user, Shipment $shipment): Response
    {
        return $this->view($user, $shipment);
    }

    /**
     * A parcel is not deleted. The record of where something was posted is what
     * answers «it never arrived» three weeks later, and the retention sweep is
     * what eventually clears the address out of it.
     */
    public function delete(User $user, Shipment $shipment): Response
    {
        return Response::deny('لا تُحذف الشحنات.');
    }
}
