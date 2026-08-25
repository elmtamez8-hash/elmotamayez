<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Enums\OffboardingStatus;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Shared\Contracts\TeacherOffboardingDirectory;

/**
 * The only implementation of {@see TeacherOffboardingDirectory}.
 *
 * ⚠️ `withoutWorkspaceScope()` WITH AN EXPLICIT `workspace_id`, and the ambient
 * context is never the right one here. The caller is `ConversationPolicy`, asked
 * on behalf of a STUDENT — who is a member of no workspace, so
 * `WorkspaceContext::id()` is null and the scope adds no condition at all — or on
 * behalf of an officer, whose context is their OWN workspace and not the one they
 * are asking about. Left scoped, this answers `false` for every student, which is
 * the answer that keeps the defect.
 *
 * Memoised per workspace and registered `scoped()`: `post()` asks once per message
 * and once per screen of a conversation list, and a `singleton()` would keep
 * answering `false` inside a queue worker long after the exit completed.
 */
final class EloquentTeacherOffboardingDirectory implements TeacherOffboardingDirectory
{
    /** @var array<int, bool> */
    private array $departed = [];

    public function hasDeparted(int $workspaceId): bool
    {
        // ⚠️ COMPLETED, NEVER MERELY REQUESTED. FR-033 promises students an
        // ANNOUNCED notice period during which the teacher is still delivering
        // the lessons it exists to let them finish — closing the chat the moment
        // an exit is filed takes away the way they ask about those very lessons.
        return $this->departed[$workspaceId] ??= TeacherOffboarding::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('status', OffboardingStatus::Completed->value)
            ->exists();
    }
}
