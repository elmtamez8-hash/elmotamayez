<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Which course a live session belongs to, asked by a module that holds only the
 * session's id.
 *
 * Exists so `Media` can scope a class recording that is still being ingested —
 * an asset whose owner is the SESSION, before `PublishRecordingAsLesson` hands
 * it to a lesson — without reaching into LiveSessions' models (Constitution
 * III). Same shape as {@see SessionAttendanceDirectory}: LiveSessions owns the
 * row and binds the implementation, Media depends on this interface alone.
 *
 * ⚠️ `null` MEANS «NO COURSE», AND A CALLER HANDS IT ON UNCHANGED. A session set
 * for a subject alone has no course, and a session that is gone has none
 * either; {@see AssistantScopeDirectory::mayActOnCourse()} reads that null as
 * outside every confinement — a confined assistant refused, everybody else
 * untouched — which is the rule an exam set for no course already follows.
 */
interface SessionCourseDirectory
{
    /** Whether this `owner_type` names a live session. */
    public function isSessionOwner(string $ownerType): bool;

    /** The session's course id, or null when it has none or no longer exists. */
    public function courseIdForSession(int $classSessionId): ?int;
}
