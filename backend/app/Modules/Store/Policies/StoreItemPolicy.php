<?php

declare(strict_types=1);

namespace App\Modules\Store\Policies;

use App\Models\User;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Who may manage the products in a store (spec 011 · US1).
 *
 * ⚠️ THE ALLOW DIRECTION IS TESTED, NOT ONLY THE DENY. Laravel's policy guesser
 * fails OPEN into "no policy applies" — and it fails open exactly when one
 * policy serves two models, which is the shape this repository keeps choosing.
 * A deny-only test passes just as well against a `Gate::policy()` line nobody
 * added. Spec 009's `taxonomy.manage` is the precedent: declared, seeded,
 * asserted platform-level, and read by no file at all.
 *
 * ⚠️ AND THERE IS NO `view()` HERE THAT A STUDENT USES. Browsing the store is a
 * question about a workspace the student is not a member of, so it is answered
 * by the Action's own `is_active` predicate rather than by a policy that would
 * be consulted with a null workspace context and no team id.
 */
class StoreItemPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::STORE_ITEMS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, StoreItem $item): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($item))->denied()) {
            return $workspaceCheck;
        }

        return $this->viewAny($user);
    }

    public function create(User $user): Response
    {
        return $this->viewAny($user);
    }

    public function update(User $user, StoreItem $item): Response
    {
        return $this->view($user, $item);
    }

    /**
     * ⚠️ DELETING IS REFUSED FOR EVERYONE, AND `is_active` IS THE WAY OUT.
     * `store_orders` points at this row and a buyer's receipt must keep naming
     * what they bought — a deleted product turns every past purchase into a line
     * with nothing behind it, including the file a digital buyer still owns.
     */
    public function delete(User $user, StoreItem $item): Response
    {
        return Response::deny('لا يُحذف منتج بيع؛ أوقِف عرضه بدلاً من ذلك.');
    }
}
