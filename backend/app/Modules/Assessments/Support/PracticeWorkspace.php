<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\WorkspaceContext;

/**
 * Which teacher's bank a student is asking about (spec 012).
 *
 * ⚠️ EXTRACTED RATHER THAN COPIED. `StartAdaptiveSession` and `CreateStudyRoom`
 * both need it, both read a PER-WORKSPACE feature switch off the answer, and a
 * second spelling of «which teacher» is a second place for a named uuid to fall
 * through to a bank that is not theirs. `MistakeController::practiceWorkspace()`
 * is the ladder this follows.
 *
 * ⚠️ AND THE CONTEXT IS CONSULTED FIRST, FOR A READER WHO HAS ONE. A teacher
 * trying their own bank has a workspace context; a real student has none at all,
 * because a student is a member of no workspace — so the enrolments are the
 * answer for everybody the feature is actually for.
 */
class PracticeWorkspace
{
    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

    /**
     * The workspace id behind this teacher uuid, or null to refuse.
     *
     * A named uuid that is not one of the reader's REFUSES rather than falling
     * through to a different teacher's bank; an empty uuid is answered only when
     * there is exactly one candidate, because guessing between two is a guess
     * about whose questions somebody sees.
     */
    public function resolve(User $student, string $teacherUuid): ?int
    {
        $context = app(WorkspaceContext::class)->id();

        $readable = $context !== null
            ? [$context]
            : $this->enrollments->activeWorkspaceIdsFor($student);

        if ($teacherUuid !== '') {
            $named = (int) Workspace::query()->withoutGlobalScopes()->where('uuid', $teacherUuid)->value('id');

            return in_array($named, $readable, true) ? $named : null;
        }

        return count($readable) === 1 ? $readable[0] : null;
    }
}
