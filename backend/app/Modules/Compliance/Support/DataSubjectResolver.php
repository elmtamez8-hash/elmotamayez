<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Support;

use App\Models\User;
use App\Modules\Compliance\Models\DataRequest;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\GuardianPermission;

/**
 * Who a request is about, resolved ONCE for the whole walk (spec 013).
 *
 * ⚠️ THE ALTERNATIVE IS THIRTEEN COPIES OF ONE QUESTION. "Which workspaces hold
 * rows about this person" and "which enrolments are theirs" are asked by more than
 * one implementor of {@see PersonalDataOwner}, and every one
 * of them would have to get `withoutWorkspaceScope()` right on its own — thirteen
 * places for one decision, twelve of which nobody reviews.
 *
 * ⚠️ AND THE ENROLMENT IDS COME THROUGH `EnrollmentDirectory`, NEVER FROM A QUERY
 * HERE. `Compliance` naming `enrollments` is the Constitution III breach the whole
 * tag mechanism exists to avoid — the same reason `CreateFreezePeriod` asks the
 * directory rather than reading the table.
 */
final class DataSubjectResolver
{
    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

    public function forRequest(DataRequest $request): DataSubject
    {
        /** @var User $user */
        $user = User::query()->findOrFail($request->subject_user_id);

        return $this->forUser($user, $request->granted_scope);
    }

    /**
     * @param  list<string>|null  $grantedScope  null when the subject asked for
     *                                           their own data — see
     *                                           {@see DataSubject::mayReceive()}
     */
    public function forUser(User $user, ?array $grantedScope = null): DataSubject
    {
        /*
        | ⚠️ WORKSPACE MEMBERSHIP, NOT ENROLMENT. Only `AcceptInvitation` and
        | `CreateWorkspace` write this pivot, so a student is a member of nothing
        | and the list is empty for them — which is correct: their rows are found by
        | their own user id, and the one implementor that needs this list
        | (`CoursesPersonalData`) is answering FR-034's promise to a departing
        | TEACHER.
        */
        $workspaceIds = $user->workspaces()
            ->pluck('workspaces.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return new DataSubject(
            user: $user,
            workspaceIds: array_values($workspaceIds),
            enrollmentIds: $this->enrollments->enrollmentIdsFor($user),
            grantedScope: $grantedScope === null ? null : self::permissions($grantedScope),
        );
    }

    /**
     * ⚠️ AN UNKNOWN NAME IS DROPPED, NEVER TREATED AS A GRANT. `granted_scope` is a
     * json column written when the request was made; a permission renamed or
     * removed since then must not resolve into "everything" by falling through a
     * `match` — that is a widening nobody asked for, arriving by a spelling change.
     *
     * @param  list<string>  $names
     * @return list<GuardianPermission>
     */
    private static function permissions(array $names): array
    {
        return array_values(array_filter(array_map(
            fn (string $name): ?GuardianPermission => GuardianPermission::tryFrom($name),
            $names,
        )));
    }
}
