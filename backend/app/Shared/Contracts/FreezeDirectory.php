<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Which days were covered by a freeze period (spec 009 · FR-015).
 *
 * A streak must not break over a holiday the teacher declared, and frozen days
 * must not count as activity either. Gamification cannot answer that itself:
 * `FreezePeriod` belongs to LiveSessions.
 *
 * ⚠️ AND THE IMPLEMENTATION MUST USE `withoutWorkspaceScope()`. A streak is
 * PLATFORM-owned — one per student across every teacher — while a freeze period
 * is workspace-scoped. A scoped query would answer for whichever workspace the
 * context happened to resolve to (null, for a student, which means *no filter*
 * only by accident) and would silently answer about one teacher a question asked
 * about the whole person.
 */
interface FreezeDirectory
{
    /**
     * Whether ANY workspace this student studies in had a freeze covering this
     * local calendar day.
     *
     * Any, not all: if one of their teachers declared a holiday the student was
     * legitimately away, and a streak is a property of the student rather than of
     * a course.
     *
     * @param  string  $dayKey  a local calendar day, `Y-m-d`
     */
    public function isFrozenForStudent(int $studentUserId, string $dayKey): bool;
}
