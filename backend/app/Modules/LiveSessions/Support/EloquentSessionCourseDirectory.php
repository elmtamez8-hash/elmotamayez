<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Contracts\SessionCourseDirectory;

/**
 * LiveSessions' answer to «which course is this session in?».
 *
 * Read without the workspace scope: the caller has already pinned the
 * workspace (the asset's own), and a scoped read that came back empty under a
 * mismatched context would read as «no course» — which refuses a confined
 * assistant their OWN course's recording.
 */
class EloquentSessionCourseDirectory implements SessionCourseDirectory
{
    public function isSessionOwner(string $ownerType): bool
    {
        return $ownerType === ClassSession::class;
    }

    public function courseIdForSession(int $classSessionId): ?int
    {
        $courseId = ClassSession::query()
            ->withoutWorkspaceScope()
            ->whereKey($classSessionId)
            ->value('course_id');

        return $courseId === null ? null : (int) $courseId;
    }
}
