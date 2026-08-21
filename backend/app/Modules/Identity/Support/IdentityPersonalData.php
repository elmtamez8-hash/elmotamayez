<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Compliance\Support\Anonymiser;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use App\Shared\Support\GuardianPermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Identity's half of the data-rights contract (spec 013).
 *
 * ⚠️ THE `users` ROW IS ANONYMISED, NEVER DELETED — and this is the single most
 * consequential line in any implementor of this contract.
 *
 * `workspaces.owner_user_id` is `cascadeOnDelete()`, and every other table carries
 * `workspace_id` with NO foreign key. So deleting a teacher's account destroys
 * their workspace and leaves their courses, sessions and enrolments pointing at an
 * id that no longer exists — unreachable behind `WorkspaceScope` and undeleted, at
 * the same time. And a database cascade INSTANTIATES NO MODEL, so the ledger's
 * own append-only guard never fires: the claim that `SC-007` holds "by
 * construction" would quietly stop being true.
 *
 * @see PersonalDataOwner
 */
class IdentityPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'identity';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['student_name', 'contact_phone', 'date_of_birth'];
    }

    /**
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        $user = $subject->user;

        yield 'student_name' => [[
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'country' => $user->country,
            'created_at' => $user->created_at?->toIso8601String(),
        ]];

        yield 'contact_phone' => [[
            'phone' => $user->phone,
        ]];

        $profile = $user->studentProfile()->first();

        /*
        | ⚠️ THE KEY IS YIELDED EVEN WHEN THERE IS NO PROFILE. A teacher has none, so
        | the `if` alone produced no `date_of_birth.json` at all — and a file that
        | does not exist is silence, where "we hold no date of birth for you" is the
        | answer a person asking is entitled to. `ExportWalk::none()` carries the
        | same reason for the six modules that bail out for their own reasons.
        */
        if ($profile === null) {
            yield 'date_of_birth' => [];
        }

        if ($profile !== null) {
            yield 'date_of_birth' => [[
                'date_of_birth' => $profile->date_of_birth,
                // ⚠️ SHIPPED WITH THE DATE, because a date we GUESSED from a
                // guardian's stated age is a different fact from one the person
                // gave us — and the subject is entitled to know which they are
                // looking at before they correct it.
                'is_estimated' => $profile->dob_is_estimated,
                'grade_level' => $profile->grade_level_slug,
                // Passed through as stored: the column carries no cast on the model,
                // so treating it as a Carbon here is a runtime error waiting for the
                // first erased account with a transfer date.
                'ownership_transferred_at' => $profile->ownership_transferred_at,
            ]];
        }

        /*
        | ⚠️ GUARDIANS ARE PART OF THE SUBJECT'S OWN RECORD, and they are gated.
        | A guardian who was granted "attendance" alone must not receive the list
        | of the OTHER guardians — that is personal data about third parties, and
        | a custody dispute is exactly the situation a rights request is made in.
        */
        if ($subject->mayReceive(GuardianPermission::DataRights)) {
            /*
            | ⚠️ FILED UNDER `student_name`, NOT UNDER A CATEGORY OF ITS OWN. Every
            | key yielded here becomes a file in the archive, and a key that no
            | `data_categories` row declares is a file with no retention rule and no
            | owner — invisible to `PersonalDataContractCoverageTest`, and to the
            | nightly sweep that reads the same catalogue.
            */
            yield from ExportWalk::keyed(
                'student_name',
                ParentStudentRelation::query()->where('student_user_id', $user->getKey()),
                fn (ParentStudentRelation $relation): array => [
                    'relation_type' => $relation->relation_type,
                    'status' => $relation->status,
                    'permissions' => $relation->permissions,
                    'created_at' => ExportWalk::at($relation->created_at),
                ],
            );
        }
    }

    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        if ($mode === ErasureMode::Retain) {
            return 0;
        }

        $user = $subject->user->fresh();

        if ($user === null || $this->alreadyAnonymised($user)) {
            return 0;
        }

        /*
        | ⚠️ ONE TRANSACTION FOR THE WHOLE PERSON, not one per table. A half
        | anonymised account — name cleared, phone kept — is a re-identification
        | hole, and erasure does not reverse. The row count is small and bounded,
        | so there is no batching argument against it.
        */
        return DB::transaction(function () use ($user): int {
            $anonymiser = app(Anonymiser::class);

            $user->forceFill([
                'first_name' => $anonymiser->name(),
                'last_name' => '',
                'email' => $anonymiser->email($user->getKey()),
                'phone' => null,
                'country' => null,
            ])->save();

            $user->studentProfile()->update([
                'date_of_birth' => null,
                'dob_is_estimated' => null,
                'guardian_contact' => null,
            ]);

            // The relations name a child by hand-written string, so they go
            // entirely rather than being blanked.
            ParentStudentRelation::query()->where('student_user_id', $user->getKey())->delete();

            return 1;
        });
    }

    /** @param list<int> $exemptUserIds */
    public function expire(
        string $category,
        CarbonImmutable $before,
        ExpiryBehaviour $mode,
        int $limit,
        array $exemptUserIds = [],
    ): int {
        /*
        | ⚠️ NOTHING IN THIS MODULE EXPIRES ON A CLOCK, and saying so is the answer
        | rather than an omission. A name, a phone number and a date of birth are
        | held for as long as the ACCOUNT is — they have no age of their own, and a
        | sweep that deleted a living user's name after N days would break the
        | product on a schedule. Their catalogue rows carry a null retention, so
        | the sweep never reaches here; this method exists because the contract has
        | five functions and a silent `return 0` with no reason is how the next
        | reader concludes it was forgotten.
        */
        return 0;
    }

    private function alreadyAnonymised(User $user): bool
    {
        return str_starts_with((string) $user->email, 'anonymised+');
    }
}
