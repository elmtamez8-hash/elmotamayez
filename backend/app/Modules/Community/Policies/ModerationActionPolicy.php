<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may hide a message and ban a participant (`FR-021`).
 *
 * ⚠️ THE TEACHER **AND WHOEVER THEY DELEGATED IT TO**, which is why this is a
 * permission and not an owner check. A rule reading «the teacher alone» would
 * make the one person who cannot watch the room all day the only person who can
 * act in it — and FR-021 says «ومن فُوِّض» in as many words.
 *
 * ⚠️ AND IT IS `chat.moderate`, NOT `chat.reply`. Answering students and silencing
 * them are different powers over the same people; one constant for both would
 * make every assistant who may reply a moderator too, with no way for an owner to
 * separate them. Both live on `$teacher` in `RolePermissionMatrix`, and each is
 * ticked onto a named assistant deliberately.
 *
 * ⚠️ AND THE WORKSPACE IS PASSED IN, never read from the ambient context. The
 * subject's own workspace is what the decision belongs to — a moderator whose
 * context points elsewhere must not act on a room they do not moderate, and
 * `WorkspaceContext::id()` falls back to `users.last_workspace_id` for everybody.
 */
class ModerationActionPolicy
{
    public function moderate(User $user, int $workspaceId): Response
    {
        $isMember = $user->workspaces()
            ->withoutGlobalScopes()
            ->whereKey($workspaceId)
            ->exists();

        if (! $isMember) {
            return Response::deny('لا تملك صلاحيّة الإشراف على هذه المساحة.');
        }

        return $user->hasPermissionTo(Permissions::CHAT_MODERATE)
            ? Response::allow()
            : Response::deny('لا تملك صلاحيّة الإشراف على الشات.');
    }
}
