<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Actions\ActivateStudentAccount;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Identity\Support\UserStatus;
use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Payments\Actions\RecordTermsConsent;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Models\TermsConsent;
use App\Shared\Support\GuardianPermission;

/**
 * No minor's account is usable without a recorded guardian consent
 * (SC-001 · FR-003 · FR-005).
 *
 * ⚠️ AND THE CRITERION IS ABOUT THE RECORD, NOT ONLY THE GATE. "Consent" that is
 * a boolean somewhere answers the one question nobody asks in a dispute — so the
 * assertions below check WHO signed, ABOUT WHOM, WHEN, FROM WHERE and TO WHICH
 * VERSION, because a row missing any of those is a consent that cannot be relied
 * on afterwards.
 */
function guardianWithVerifiedPhone(string $phone = '+97455500001'): User
{
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    ContactVerification::query()->create([
        'user_id' => $guardian->getKey(),
        'channel' => NotificationChannel::WhatsApp->value,
        'contact_value' => $phone,
        'code_hash' => 'x',
        // NOT NULL on the column. Already past — a VERIFIED row's expiry is about
        // the code, and the code was used.
        'expires_at' => now()->subMinutes(5),
        'verified_at' => now(),
    ]);

    return $guardian;
}

function activeGuardianRelation(User $guardian, User $student): ParentStudentRelation
{
    return ParentStudentRelation::query()->updateOrCreate(
        [
            'guardian_user_id' => $guardian->getKey(),
            'student_user_id' => $student->getKey(),
        ],
        [
            'student_name' => 'طالب',
            'relation_type' => RelationType::Parent->value,
            'permissions' => [GuardianPermission::DataRights->value],
            'status' => RelationStatus::Active->value,
        ],
    );
}

it('leaves a minor pending and refuses activation with no consent', function (): void {
    $student = User::factory()->create([
        'platform_role' => PlatformRole::Student,
        'status' => UserStatus::PendingGuardianConsent->value,
    ]);

    expect(fn () => app(ActivateStudentAccount::class)->handle($student))
        ->toThrow(DomainException::class);

    expect($student->fresh()?->status)->toBe(UserStatus::PendingGuardianConsent->value);
});

it('activates on a consent that records who, about whom, when, from where and to which version', function (): void {
    $student = User::factory()->create([
        'platform_role' => PlatformRole::Student,
        'status' => UserStatus::PendingGuardianConsent->value,
    ]);

    $guardian = guardianWithVerifiedPhone();
    activeGuardianRelation($guardian, $student);

    app(RecordTermsConsent::class)->handle(
        $guardian,
        $student,
        ConsentDocument::DataProcessing,
        '203.0.113.9',
        'Mozilla/5.0',
        ['student_name', 'class_recording'],
    );

    // The gate opened — through the EVENT, not a direct call, which is what makes
    // this an assertion about the wiring rather than about one Action.
    expect($student->fresh()?->status)->toBe(UserStatus::Active->value);

    $row = TermsConsent::query()->where('student_user_id', $student->getKey())->sole();

    expect((int) $row->user_id)->toBe((int) $guardian->getKey())
        ->and((int) $row->student_user_id)->toBe((int) $student->getKey())
        ->and($row->consented_at)->not->toBeNull()
        ->and($row->ip_address)->toBe('203.0.113.9')
        ->and($row->user_agent)->toBe('Mozilla/5.0')
        ->and($row->version)->not->toBeEmpty()
        // ⚠️ THE FIFTH THING `FR-005` ASKS FOR, and the one three planning
        // documents recorded as already implemented when there was no column for
        // it at all.
        ->and($row->categories)->toBe(['student_name', 'class_recording']);
});

/*
 * ⚠️ AN UNAUTHORISED GUARDIAN CANNOT CONSENT, AND UNTIL THIS PHASE THE
 * PERMISSION THEY NEEDED WAS THE WRONG ONE.
 *
 * `RecordTermsConsent` asked for `GuardianPermission::Payments` for every
 * document, so consenting to the processing of one's own child's data required
 * authority over the money — a coupling with no meaning, and it made R6's rule
 * ("an authorised guardian grants, an unauthorised one does not") impossible to
 * state, because there was nothing to be authorised FOR.
 */
it('refuses a guardian who holds every permission except data rights', function (): void {
    $student = User::factory()->create([
        'platform_role' => PlatformRole::Student,
        'status' => UserStatus::PendingGuardianConsent->value,
    ]);

    $guardian = guardianWithVerifiedPhone('+97455500002');

    ParentStudentRelation::query()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $student->getKey(),
        'student_name' => 'طالب',
        'relation_type' => RelationType::Parent->value,
        // Everything but the one that matters.
        'permissions' => [
            GuardianPermission::Attendance->value,
            GuardianPermission::Payments->value,
            GuardianPermission::Schedule->value,
            GuardianPermission::Results->value,
            GuardianPermission::AcademicWarnings->value,
        ],
        'status' => RelationStatus::Active->value,
    ]);

    expect(fn () => app(RecordTermsConsent::class)->handle(
        $guardian,
        $student,
        ConsentDocument::DataProcessing,
        '203.0.113.9',
        null,
        ['student_name'],
    ))->toThrow(RuntimeException::class);

    expect($student->fresh()?->status)->toBe(UserStatus::PendingGuardianConsent->value);
});

/*
 * A stranger cannot sign for a child, and the refusal says nothing.
 *
 * The response is identical to the one for a student uuid that matches nobody —
 * two different facts, one answer, so the endpoint cannot be used to discover who
 * exists on the platform.
 */
it('refuses a signer with no relation at all', function (): void {
    $student = User::factory()->create([
        'platform_role' => PlatformRole::Student,
        'status' => UserStatus::PendingGuardianConsent->value,
    ]);

    $stranger = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    expect(fn () => app(RecordTermsConsent::class)->handle(
        $stranger,
        $student,
        ConsentDocument::DataProcessing,
        '203.0.113.9',
        null,
        ['student_name'],
    ))->toThrow(RuntimeException::class);
});
