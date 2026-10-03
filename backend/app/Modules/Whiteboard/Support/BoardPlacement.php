<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Contracts\AssistantScopeDirectory;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * Where a board hangs: an optional course and an optional lesson of that course.
 *
 * - Both given: the lesson must belong to the course.
 * - The lesson alone: the course is derived from it.
 * - A confined assistant must name a course inside their scope — a course-less
 *   board is refused to them, like a course-less exam or assignment.
 *
 * Read inside the current workspace (the scope applies, and the id is compared
 * explicitly as well): a uuid from another academy resolves to nothing.
 */
final class BoardPlacement
{
    public function __construct(private readonly AssistantScopeDirectory $scope) {}

    /**
     * The live class a board is opened from (story 7): one of this workspace's,
     * and one the actor may HOST — a board is linked from inside the room.
     */
    public function session(?string $sessionUuid, int $workspaceId, User $actor): ?ClassSession
    {
        if ($sessionUuid === null) {
            return null;
        }
        $session = ClassSession::query()->where('workspace_id', $workspaceId)->where('uuid', $sessionUuid)->first();
        if ($session === null) {
            throw new DomainException('الحصة غير موجودة.');
        }
        Gate::forUser($actor)->authorize('host', $session);

        return $session;
    }

    /** @return array{0: int|null, 1: int|null} */
    public function resolve(?string $courseUuid, ?string $lessonUuid, int $workspaceId, User $actor): array
    {
        $course = $courseUuid === null ? null : Course::query()
            ->where('workspace_id', $workspaceId)
            ->where('uuid', $courseUuid)
            ->first();

        if ($courseUuid !== null && $course === null) {
            throw new DomainException('الكورس غير موجود.');
        }

        $lesson = $lessonUuid === null ? null : Lesson::query()
            ->where('workspace_id', $workspaceId)
            ->where('uuid', $lessonUuid)
            ->first();

        if ($lessonUuid !== null && $lesson === null) {
            throw new DomainException('الدرس غير موجود.');
        }

        if ($lesson !== null && $course !== null && (int) $lesson->course_id !== (int) $course->getKey()) {
            throw new DomainException('هذا الدرس لا يتبع هذا الكورس.');
        }

        $courseId = $course?->getKey() ?? $lesson?->course_id;

        if (! $this->scope->mayActOnCourse($actor, $workspaceId, $courseId === null ? null : (int) $courseId)) {
            throw new DomainException('اختر كورساً من الكورسات التي تعمل عليها.');
        }

        return [$courseId === null ? null : (int) $courseId, $lesson === null ? null : (int) $lesson->getKey()];
    }
}
