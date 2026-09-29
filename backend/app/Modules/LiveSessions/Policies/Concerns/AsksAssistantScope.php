<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Policies\Concerns;

use App\Models\User;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Contracts\AssistantScopeDirectory;
use Illuminate\Auth\Access\Response;

/**
 * Spec 010 · FR-005 for the staff branch of the rows that hang off a session —
 * a booking, a private-hour request, a reschedule request, a freeze.
 *
 * Asked BESIDE the permission, never instead of it, and only on the staff
 * branch: a student's own row is decided by ownership above it. A `null` course
 * is outside every confinement — `AssistantScopeDirectory::mayActOnCourse()`
 * refuses it to a confined assistant and passes everybody else.
 */
trait AsksAssistantScope
{
    private function withinAssistantScope(User $user, int $workspaceId, ?int $courseId): Response
    {
        return app(AssistantScopeDirectory::class)->mayActOnCourse($user, $workspaceId, $courseId)
            ? Response::allow()
            : Response::deny('هذه الحصّة خارج نطاق عملك.');
    }

    /**
     * The session's course, read with the workspace scope bypassed: the caller
     * has already pinned the workspace, and a scoped read that came back empty
     * would turn into a `null` course and refuse a confined assistant their OWN
     * course's row. A session that is gone is that `null`.
     */
    private function courseOfSession(?int $sessionId): ?int
    {
        if ($sessionId === null) {
            return null;
        }

        $courseId = ClassSession::query()
            ->withoutWorkspaceScope()
            ->whereKey($sessionId)
            ->value('course_id');

        return $courseId === null ? null : (int) $courseId;
    }
}
