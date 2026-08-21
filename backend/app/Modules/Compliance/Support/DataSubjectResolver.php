<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Support;

use App\Models\User;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Tenancy\Models\Workspace;
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
        | ⚠️ WORKSPACES THIS PERSON OWNS — NOT ONES THEY BELONG TO, AND THE
        | DIFFERENCE WAS A LEAK MEASURED AGAINST THE SEEDED DATABASE.
        |
        | The first version read `$user->workspaces()`, on the stated ground that a
        | student is a member of no workspace at all. That is what this repository's
        | own notes say, and it is FALSE in practice: `workspace_members` carries a
        | `role` column with `student` in it, the demo seeder writes one, and
        | `addWorkspaceMember()` writes one in every test that needs a student. So
        | the very first real export run — `student@example.com`, one membership row,
        | role `student` — produced a 54 KB `authored_content.json` holding 144 of
        | the TEACHER's courses and lessons, drafts included, inside the student's
        | own data archive. Nothing in the suite saw it, because every fixture built
        | its student with `User::factory()` and no membership.
        |
        | Ownership is the predicate FR-034 actually names: a departing TEACHER
        | receives a copy of their content, and the teacher is the workspace's owner.
        | A membership row is a statement about access, not about authorship.
        */
        $workspaceIds = Workspace::query()
            ->where('owner_user_id', $user->getKey())
            ->pluck('id')
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
