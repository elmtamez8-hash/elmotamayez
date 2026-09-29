<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Community\Models\Conversation;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Support\Collection;

/**
 * The course a room hangs off — its session's, its lesson's or its group's.
 *
 * ⚠️ ONE SPELLING FOR THE DOOR AND FOR THE LIST. `ConversationPolicy` asks it
 * about one room (`withinStaffScope()`), `ListConversations` and
 * `ConversationResource::can_moderate` ask it about a whole screen at once; two
 * copies of «which course is this room under» would put one answer on the
 * screen and another at the door.
 *
 * ⚠️ EVERY READ BYPASSES THE WORKSPACE SCOPE. A scoped read that came back empty
 * would be a `null` course, and a `null` course refuses a confined assistant
 * their OWN course's room. The ids come from the conversation rows themselves,
 * which carry their workspace, so the bypass widens nothing.
 */
final class RoomCourses
{
    /** The course of one room, or null (a private thread, or a course-less session). */
    public function of(Conversation $conversation): ?int
    {
        return $this->forMany(collect([$conversation]))[(int) $conversation->getKey()] ?? null;
    }

    /**
     * The course of every room in the list, keyed by conversation id — at most
     * three queries whatever the length, one per kind actually present.
     *
     * A private thread, or a room whose parent names no course, maps to null.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return array<int, int|null>
     */
    public function forMany(Collection $conversations): array
    {
        $sessionIds = $this->ids($conversations, 'class_session_id');
        $lessonIds = $this->ids($conversations, 'lesson_id');
        $cohortIds = $this->ids($conversations, 'cohort_id');

        $sessions = $sessionIds === [] ? [] : ClassSession::query()
            ->withoutWorkspaceScope()
            ->whereKey($sessionIds)
            ->pluck('course_id', 'id')
            ->all();

        $lessons = $lessonIds === [] ? [] : Lesson::query()
            ->withoutWorkspaceScope()
            ->whereKey($lessonIds)
            ->pluck('course_id', 'id')
            ->all();

        $cohorts = $cohortIds === [] ? [] : Cohort::query()
            ->withoutWorkspaceScope()
            ->whereKey($cohortIds)
            ->pluck('course_id', 'id')
            ->all();

        $courses = [];

        foreach ($conversations as $conversation) {
            $courseId = match (true) {
                ! $conversation->kind->isPublic() => null,
                $conversation->class_session_id !== null => $sessions[(int) $conversation->class_session_id] ?? null,
                $conversation->lesson_id !== null => $lessons[(int) $conversation->lesson_id] ?? null,
                $conversation->cohort_id !== null => $cohorts[(int) $conversation->cohort_id] ?? null,
                default => null,
            };

            $courses[(int) $conversation->getKey()] = $courseId === null ? null : (int) $courseId;
        }

        return $courses;
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     * @return list<int>
     */
    private function ids(Collection $conversations, string $column): array
    {
        $ids = [];

        foreach ($conversations as $conversation) {
            if ($conversation->kind->isPublic() && $conversation->{$column} !== null) {
                $ids[] = (int) $conversation->{$column};
            }
        }

        return array_values(array_unique($ids));
    }
}
