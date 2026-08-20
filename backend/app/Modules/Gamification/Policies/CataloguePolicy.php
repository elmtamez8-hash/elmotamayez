<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Policies;

use App\Models\User;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Actions, levels and badges — platform reference data (constitution v1.2.0 §I,
 * kind ب).
 *
 * ⚠️ ONE POLICY FOR THREE MODELS, on purpose. Every one of them answers the same
 * question with the same permission, and three files differing only in a
 * type-hint is three places for the next person to change two of.
 *
 * The rows have no individual owner and no `workspace_id`, so there is nothing to
 * check ownership against: the write permission IS the guard, and it is a
 * PLATFORM permission. The workspace owner — the highest tenant role there is —
 * must fail every write here, because what an action is worth orders the subject,
 * the grade and the platform boards for everybody.
 *
 * Reading is the same permission as writing, following CreditPackagePolicy: the
 * screen lists disabled actions and unreleased badges, which is the platform's
 * own roadmap. The STUDENT'S view of their badges never comes through here — it
 * is their own row, read by BuildProgressPayload.
 */
class CataloguePolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $this->create($user);
    }

    public function view(User $user, Model $record): Response
    {
        return $this->create($user);
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::GAMIFICATION_CATALOG_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Model $record): Response
    {
        return $this->create($user);
    }

    /**
     * Disable, never erase.
     *
     * Every award entry ever written names its action by KEY, and every badge a
     * student holds names its badge by key — deleting the catalogue row leaves
     * those pointing at nothing, and the student's profile showing a raw slug
     * where a name used to be. `is_active = false` is what every read consults.
     */
    public function delete(User $user, Model $record): Response
    {
        return Response::deny('يُعطَّل العنصر ولا يُحذف: كلُّ قيدِ منحٍ سابقٍ ما زال يشير إليه بمفتاحه.');
    }
}
