<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Models\User;
use App\Modules\Identity\Models\ParentChildLink;

/**
 * A parent may only ever see their own children (FR-075).
 *
 * The check is ownership of the link row, not the child's identity: a second
 * parent with a link to the same child passes, and a parent guessing another
 * family's child uuid does not.
 */
class ParentChildLinkPolicy
{
    public function view(User $user, ParentChildLink $link): bool
    {
        return $user->getKey() === $link->parent_id;
    }

    public function delete(User $user, ParentChildLink $link): bool
    {
        return $this->view($user, $link);
    }
}
