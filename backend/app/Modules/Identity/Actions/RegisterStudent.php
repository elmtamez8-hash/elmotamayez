<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Data\RegisterStudentData;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\GuardianContactResolver;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Identity\Support\UserStatus;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Actions\Action;
use App\Shared\Support\GuardianPermission;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Registered;

class RegisterStudent extends Action
{
    public function __construct(
        private readonly GuardianContactResolver $guardians,
        private readonly DispatchNotification $notifications,
    ) {}

    public function handle(RegisterStudentData $data): User
    {
        $dateOfBirth = $data->dateOfBirth === null ? null : CarbonImmutable::parse($data->dateOfBirth);

        /*
        | ⚠️ AN UNKNOWN DATE IS TREATED AS A MINOR (`FR-009ج`), not as an adult.
        | The two errors are not symmetrical: treating a child as an adult
        | processes their data with no guardian behind it, while treating an adult
        | as a child asks for a consent that turns out to be unnecessary. Only one
        | of those is reversible.
        */
        $isMinor = $dateOfBirth === null || $dateOfBirth->diffInYears(CarbonImmutable::now()) < 18;

        $user = new User;

        // forceFill, not fill: platform_role is guarded precisely so a request
        // payload can never choose it — and `status` for the same reason, since
        // it is the gate this phase adds.
        $user->forceFill([
            'first_name' => $data->firstName,
            'last_name' => $data->lastName,
            'email' => $data->email,
            'password' => $data->password,
            'phone' => $data->phone,
            'country' => $data->country,
            'platform_role' => PlatformRole::Student,
            'status' => $isMinor
                ? UserStatus::PendingGuardianConsent->value
                : UserStatus::Active->value,
        ])->save();

        // Student-only facts live in their own table (spec 004): `users` carries
        // what every account has, and nothing more.
        $user->studentProfile()->create([
            /*
            | ⚠️ THE NEW COLUMN, AND `grade_level_slug` IS LEFT NULL ON PURPOSE.
            | It is the FALLBACK for accounts that predate school years; writing
            | both would be two stored answers to one question, and the derived
            | stage would then have to choose between them.
            */
            'school_year_slug' => $data->schoolYearSlug,
            'registered_by_parent' => $data->registeredByParent,
            'date_of_birth' => $dateOfBirth?->toDateString(),
            /*
            | ⚠️ FALSE, NOT NULL: this person typed their own date. `true` is
            | reserved for the migration's backfill, which derived a date from a
            | guardian's stated AGE and anchored it on the day it ran — a guess
            | that must stay marked as one.
            */
            'dob_is_estimated' => $dateOfBirth === null ? null : false,
            // Kept even when it resolves to nobody — see GuardianContactResolver.
            'guardian_contact' => $data->guardianContact,
            /*
            | ⚠️ RESOLVED HERE, AND AN UNKNOWN SLUG BECOMES NULL RATHER THAN AN
            | ERROR. The form request has already refused anything outside the
            | catalogue, so the only callers that can reach this with a slug that
            | resolves to nothing are a seeder and a test — and for them «we never
            | asked» is the truth the nullable column exists to record.
            */
            'region_id' => $this->regionId($data->regionSlug),
        ]);

        if ($isMinor) {
            $this->inviteGuardian($user, $data->guardianContact);
        }

        // No workspace, no membership, no role (FR-010). A student browses the
        // marketplace across every workspace; belonging to one would narrow that
        // and would hand them a tenant role they never asked for.
        event(new Registered($user));

        return $user;
    }

    /** The catalogue row behind a slug, or null when there is nothing to file. */
    private function regionId(?string $slug): ?int
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        $id = Region::query()->where('slug', $slug)->value('id');

        return is_int($id) ? (int) $id : null;
    }

    /**
     * ⚠️ THE RELATION ROW IS THE INVITATION, and there is deliberately no second
     * token mechanism to build, expire and revoke.
     *
     * `Pending` grants nothing at all today — `EloquentGuardianDirectory` asks
     * `->active()` in all three of its methods — so the row is an invitation
     * WITHOUT AUTHORITY BY CONSTRUCTION, not by a check anyone has to remember.
     *
     * ⚠️ AND IT IS CREATED FROM THE STUDENT'S SIDE, so it carries no permissions
     * yet. `DataRights` is granted when the guardian ACCEPTS: a self-registering
     * child must not be able to hand someone authority over their record by typing
     * a phone number.
     */
    private function inviteGuardian(User $student, ?string $contact): void
    {
        $guardian = $this->guardians->resolve($contact);

        if ($guardian === null) {
            // No account behind the number. The contact is on the profile, the
            // account stays pending, and the registration response tells the
            // student what has to happen — there is nothing here to invent.
            return;
        }

        $relation = ParentStudentRelation::query()->firstOrCreate(
            [
                'guardian_user_id' => $guardian->getKey(),
                'student_user_id' => $student->getKey(),
            ],
            [
                'student_name' => trim($student->first_name.' '.$student->last_name),
                'relation_type' => RelationType::Parent->value,
                // Empty, and filled on acceptance. See the note above.
                'permissions' => [GuardianPermission::DataRights->value],
                'status' => RelationStatus::Pending->value,
            ],
        );

        // A guardian who already holds an ACTIVE relation with this student needs
        // no invitation — they can consent from the screen they already have.
        if (! $relation->wasRecentlyCreated) {
            return;
        }

        $this->notifications->handle(new NotificationRequest(
            recipient: $guardian,
            type: NotificationType::GuardianConsentRequired,
            variables: ['student_name' => trim($student->first_name.' '.$student->last_name)],
            // Named as the subject so a guardian of three can tell which child the
            // message is about — the type is ADDRESSED to a guardian rather than
            // copied to one, which is why it is absent from `targetsGuardians()`.
            subject: $student,
        ));
    }
}
