<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

/**
 * What a teacher may open to every assistant of their workspace, from
 * «فريق المساعدين» (owner decision 2026-10-21).
 *
 * The roles screen that was meant to do this sits in `/admin`, which a teacher
 * has not reached since the panel became the platform's alone — so every
 * assistant carried the narrow default for ever. These four are the jobs a
 * teacher actually hands over; each permission sits on `$teacher` in
 * `RolePermissionMatrix`, never a financial one, so the assistant wall in
 * `Gate::before` is never what stops them.
 *
 * ⚠️ A GROUP IS ON ONLY WHEN EVERY ONE OF ITS PERMISSIONS IS ON THE ROLE, and
 * switching it off revokes exactly its own — anything a super admin ticked by
 * hand on the same role is left alone.
 */
final class AssistantPowers
{
    /** @var array<string, list<string>> */
    public const GROUPS = [
        'grading' => [Permissions::GRADING_PERFORM],
        'chat' => [Permissions::CHAT_REPLY],
        'sessions' => [Permissions::SESSIONS_MANAGE, Permissions::SESSIONS_HOST, Permissions::ATTENDANCE_OVERRIDE],
        // `questions.manage` is the workspace's whole shared bank, which is why
        // spec 008 took it off the default; here the teacher opts in.
        'exams' => [Permissions::QUESTIONS_MANAGE, Permissions::EXAMS_PUBLISH],
    ];
}
