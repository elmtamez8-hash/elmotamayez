<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Support\GuardianContactResolver;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Identity\Support\UserStatus;
use App\Modules\Notifications\Actions\ConfirmContactVerification;
use App\Modules\Notifications\Actions\RequestContactVerification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Auth\Events\Verified;

/*
| A GUARDIAN WHO SIGNS UP AFTER THEIR CHILD.
|
| `student_profiles.guardian_contact` had a writer (`RegisterStudent`) and no
| reader: when the number named nobody yet, signup did nothing and nothing ever
| looked again. `InviteGuardianOnContactVerified` is the reader — and it reads on
| VERIFICATION, never on the parent's own signup, because a number somebody typed
| is not evidence of whose it is.
*/

const LATE_GUARDIAN_NUMBER = '+97455512345';

function lateSignupChild(string $contact = LATE_GUARDIAN_NUMBER): User
{
    $child = User::factory()->create(['platform_role' => PlatformRole::Student]);

    StudentProfile::query()->create([
        'user_id' => $child->getKey(),
        'guardian_contact' => $contact,
    ]);

    return $child;
}

function lateSignupProve(User $user, string $value = LATE_GUARDIAN_NUMBER): void
{
    $issued = app(RequestContactVerification::class)->handle($user, NotificationChannel::WhatsApp, $value);

    app(ConfirmContactVerification::class)->handle($issued->verification, $issued->code);
}

it('invites the parent who proves the number the child named', function (): void {
    $child = lateSignupChild();
    $parent = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    lateSignupProve($parent);

    $relation = ParentStudentRelation::query()->sole();

    expect($relation->guardian_user_id)->toBe($parent->getKey())
        ->and($relation->student_user_id)->toBe($child->getKey())
        ->and($relation->status)->toBe(RelationStatus::Pending->value)
        ->and($relation->relation_type)->toBe(RelationType::Parent->value)
        // The child asked; the parent decides. Same row RegisterStudent writes.
        ->and($relation->requested_by_user_id)->toBe($child->getKey())
        ->and($relation->permissions)->toBe([GuardianPermission::DataRights->value])
        ->and($relation->decidableBy($parent))->toBeTrue();

    expect(Notification::query()
        ->where('recipient_user_id', $parent->getKey())
        ->where('type', NotificationType::GuardianConsentRequired->value)
        ->count())->toBe(1);

    // Pending grants nothing until the parent accepts.
    expect(app(GuardianDirectory::class)->childrenOf($parent, GuardianPermission::DataRights))->toBeEmpty();
});

it('does nothing while the number is only typed, not proved', function (): void {
    lateSignupChild();
    $parent = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    $issued = app(RequestContactVerification::class)
        ->handle($parent, NotificationChannel::WhatsApp, LATE_GUARDIAN_NUMBER);

    try {
        app(ConfirmContactVerification::class)->handle($issued->verification, '000000');
    } catch (DomainException) {
        // A wrong code proves nothing.
    }

    expect(ParentStudentRelation::query()->count())->toBe(0);
});

it('does not invite an account that is not a parent', function (): void {
    lateSignupChild();

    lateSignupProve(User::factory()->create(['platform_role' => PlatformRole::Teacher]));

    expect(ParentStudentRelation::query()->count())->toBe(0);
});

it('leaves a child who already has a live parent alone', function (): void {
    $child = lateSignupChild();
    $existing = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    ParentStudentRelation::query()->create([
        'guardian_user_id' => $existing->getKey(),
        'student_user_id' => $child->getKey(),
        'student_name' => $child->name,
        'relation_type' => RelationType::Parent->value,
        'permissions' => [GuardianPermission::DataRights->value],
        'status' => RelationStatus::Active->value,
        'requested_by_user_id' => $existing->getKey(),
    ]);

    lateSignupProve(User::factory()->create(['platform_role' => PlatformRole::Parent]));

    expect(ParentStudentRelation::query()->count())->toBe(1);
});

it('does not match a child who named a different number', function (): void {
    lateSignupChild('+97455599999');

    lateSignupProve(User::factory()->create(['platform_role' => PlatformRole::Parent]));

    expect(ParentStudentRelation::query()->count())->toBe(0);
});

it('writes one invitation however many times the number is proved', function (): void {
    lateSignupChild();
    $parent = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    lateSignupProve($parent);
    lateSignupProve($parent);

    expect(ParentStudentRelation::query()->count())->toBe(1)
        ->and(Notification::query()
            ->where('recipient_user_id', $parent->getKey())
            ->where('type', NotificationType::GuardianConsentRequired->value)
            ->count())->toBe(1);
});

/*
| ⛔ Security scan 2026-10-10, F28 — a code issued on a channel that sends
| nothing can only be GUESSED; such a «verified» number is never a guardian.
*/
it('does not invite a parent who verified the number over a channel that sends no code', function (): void {
    $child = lateSignupChild();
    $parent = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    $issued = app(RequestContactVerification::class)->handle($parent, NotificationChannel::Sms, LATE_GUARDIAN_NUMBER);
    app(ConfirmContactVerification::class)->handle($issued->verification, $issued->code);

    expect(ParentStudentRelation::query()->count())->toBe(0)
        ->and(app(GuardianContactResolver::class)->resolve(LATE_GUARDIAN_NUMBER))->toBeNull();
});

/*
| THE EMAIL DOOR (owner decision 2026-10-23). The platform has no WhatsApp
| subscription, so no number could ever be proved and every minor stayed
| pending. A minor names an email now, and the guardian proves it with the
| verification link every account receives.
*/
it('invites the parent who confirms the email the child named, with the viewing permissions', function (): void {
    $child = lateSignupChild('mona@example.test');
    $child->forceFill(['status' => UserStatus::PendingGuardianConsent->value])->save();
    $parent = User::factory()->unverified()->create([
        'platform_role' => PlatformRole::Parent,
        'email' => 'Mona@Example.test',
    ]);

    $parent->markEmailAsVerified();
    event(new Verified($parent));

    $relation = ParentStudentRelation::query()->sole();

    expect($relation->guardian_user_id)->toBe($parent->getKey())
        ->and($relation->status)->toBe(RelationStatus::Pending->value)
        // A minor's guardian follows them from the start; `Messages` stays the child's.
        ->and($relation->permissions)->toEqualCanonicalizing([
            GuardianPermission::DataRights->value,
            GuardianPermission::Attendance->value,
            GuardianPermission::Schedule->value,
            GuardianPermission::Results->value,
            GuardianPermission::Payments->value,
            GuardianPermission::AcademicWarnings->value,
        ]);
});

it('finds a parent whose email is already confirmed when the child signs up, and never an unconfirmed one or a non-parent', function (): void {
    $confirmed = User::factory()->create(['email' => 'dad@example.test', 'platform_role' => PlatformRole::Parent]);
    User::factory()->unverified()->create(['email' => 'stranger@example.test', 'platform_role' => PlatformRole::Parent]);
    User::factory()->create(['email' => 'teacher@example.test', 'platform_role' => PlatformRole::Teacher]);

    $resolver = app(GuardianContactResolver::class);

    expect($resolver->resolve('DAD@example.test')?->getKey())->toBe($confirmed->getKey())
        ->and($resolver->resolve('stranger@example.test'))->toBeNull()
        ->and($resolver->resolve('teacher@example.test'))->toBeNull();
});
