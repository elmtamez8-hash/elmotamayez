<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\ConsentRegistry;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| A guardian accepts the deferred-payment terms ON BEHALF OF their child, from
| the child's card on the guardian dashboard.
|
| Until this, the only card that offered the terms lived on the guardian's own
| `/billing` — which, without a `student`, records the GUARDIAN as the one who
| owes. That card was removed; this is the door that replaces it, and it has to
| be the same door the POST already guards: `Payments` permission, resolved
| inside the guardian's own list of children, one 403 for every refusal.
|
| ⚠️ WITHHOLDING IS THE CONSEQUENCE ASSERTED, NOT THE ROW. `is_withheld` is
| derived from five inputs and a CURRENT terms consent is one of them, so the
| proof that the guardian's signature counted for the child is the child's
| balance stopping being withheld — read through the guardian's own balance door.
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // A deferring mode, or there is nothing for a consent to unlock.
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::ManualCollection->value]);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->balance = billingBalance($this->workspace, $this->student, $this->course)->refresh();
});

/** @param list<GuardianPermission> $permissions */
function consentChildGuardian(User $student, array $permissions): User
{
    $guardian = User::factory()->create();

    ParentStudentRelation::factory()
        ->withPermissions($permissions)
        ->create([
            'guardian_user_id' => $guardian->getKey(),
            'student_user_id' => $student->getKey(),
        ]);

    return $guardian;
}

function actAsConsentGuardian(User $guardian): void
{
    Sanctum::actingAs($guardian);
    app()->forgetInstance(WorkspaceContext::class);
}

it('lets a guardian with the payments permission read and accept the terms for their child', function (): void {
    $guardian = consentChildGuardian($this->student, [GuardianPermission::Payments]);
    actAsConsentGuardian($guardian);

    $version = app(ConsentRegistry::class)->currentVersion(ConsentDocument::DeferredPaymentTerms);

    // Before: withheld — no credits and no consent, so the floor is zero. (A
    // bare array: `JsonResource::withoutWrapping()` is on globally, and the
    // client wraps it.)
    expect($this->getJson('/api/v1/billing/children/balance?student='.$this->student->uuid)
        ->assertOk()->json('0.is_withheld'))->toBeTrue();

    // The read answers the deferred-payment terms ALONE, outstanding.
    $this->getJson('/api/v1/billing/consents?student='.$this->student->uuid)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.document', ConsentDocument::DeferredPaymentTerms->value)
        ->assertJsonPath('data.0.version', $version)
        ->assertJsonPath('data.0.consented_at', null);

    $this->postJson('/api/v1/billing/consents', [
        'document' => ConsentDocument::DeferredPaymentTerms->value,
        'student' => $this->student->uuid,
    ])
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.consented_at', fn (?string $at): bool => $at !== null);

    // Two people on one row: the guardian signed, the child owes.
    $consent = TermsConsent::query()->sole();

    expect($consent->user_id)->toBe($guardian->getKey())
        ->and($consent->student_user_id)->toBe($this->student->getKey())
        ->and($consent->version)->toBe($version)
        // The guardian did NOT agree to owe for themselves.
        ->and(app(ConsentRegistry::class)->has($guardian, ConsentDocument::DeferredPaymentTerms))->toBeFalse()
        ->and(app(ConsentRegistry::class)->has($this->student, ConsentDocument::DeferredPaymentTerms))->toBeTrue();

    // After: the opening ceiling makes room for one session — no longer withheld.
    expect($this->getJson('/api/v1/billing/children/balance?student='.$this->student->uuid)
        ->assertOk()->json('0.is_withheld'))->toBeFalse();

    // And the card has nothing left to show.
    $this->getJson('/api/v1/billing/consents?student='.$this->student->uuid)
        ->assertOk()
        ->assertJsonPath('data.0.consented_at', fn (?string $at): bool => $at !== null);
});

it('refuses a guardian who holds the relation but not the payments permission — read and write', function (): void {
    $guardian = consentChildGuardian($this->student, [GuardianPermission::Attendance, GuardianPermission::DataRights]);
    actAsConsentGuardian($guardian);

    $this->getJson('/api/v1/billing/consents?student='.$this->student->uuid)->assertForbidden();

    $this->postJson('/api/v1/billing/consents', [
        'document' => ConsentDocument::DeferredPaymentTerms->value,
        'student' => $this->student->uuid,
    ])->assertForbidden();

    expect(TermsConsent::query()->count())->toBe(0);
});

it('refuses an unrelated guardian with one answer, whoever the uuid names', function (): void {
    // A real guardian — with the payments permission — of SOMEONE ELSE.
    $otherChild = User::factory()->create();
    $stranger = consentChildGuardian($otherChild, [GuardianPermission::Payments]);
    actAsConsentGuardian($stranger);

    $refusal = $this->getJson('/api/v1/billing/consents?student='.$this->student->uuid);
    $refusal->assertForbidden();

    // The same answer for a uuid that is nobody: two answers would make the
    // read a directory of who exists.
    $unknown = $this->getJson('/api/v1/billing/consents?student=00000000-0000-4000-8000-000000000000');

    expect($unknown->status())->toBe($refusal->status())
        ->and($unknown->json('message'))->toBe($refusal->json('message'));

    $this->postJson('/api/v1/billing/consents', [
        'document' => ConsentDocument::DeferredPaymentTerms->value,
        'student' => $this->student->uuid,
    ])->assertForbidden();

    expect(TermsConsent::query()->count())->toBe(0);
});

it('still answers a signer about themselves, both documents, when no student is named', function (): void {
    Sanctum::actingAs($this->student);

    $this->getJson('/api/v1/billing/consents')
        ->assertOk()
        ->assertJsonCount(count(ConsentDocument::cases()), 'data');
});
