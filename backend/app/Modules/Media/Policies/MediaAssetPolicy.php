<?php

declare(strict_types=1);

namespace App\Modules\Media\Policies;

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Support\Permissions;

/**
 * Managing an asset is managing lesson content, so it rides on LESSONS_MANAGE
 * rather than a permission of its own. A permission with no distinct actor is
 * authorisation surface bought for nothing.
 *
 * Watching is NOT covered here: that is entitlement, not permission, and it
 * lives in IssuePlaybackGrant.
 */
class MediaAssetPolicy
{
    public function create(User $user, Lesson $lesson): bool
    {
        return $user->can(Permissions::LESSONS_MANAGE)
            && $this->belongsToUsersWorkspace($user, (int) $lesson->workspace_id);
    }

    public function view(User $user, MediaAsset $asset): bool
    {
        return $user->can(Permissions::LESSONS_MANAGE)
            && $this->belongsToUsersWorkspace($user, (int) $asset->workspace_id);
    }

    public function delete(User $user, MediaAsset $asset): bool
    {
        return $user->can(Permissions::LESSONS_DELETE)
            && $this->belongsToUsersWorkspace($user, (int) $asset->workspace_id);
    }

    /**
     * Belt and braces alongside the global scope: a permission check alone would
     * pass for a teacher in another workspace who holds the same role.
     */
    private function belongsToUsersWorkspace(User $user, int $workspaceId): bool
    {
        return $user->workspaces()->where('workspaces.id', $workspaceId)->exists();
    }
}
