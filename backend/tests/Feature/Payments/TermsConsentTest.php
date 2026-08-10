<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Payments\Actions\RecordTermsConsent;
use App\Modules\Payments\Actions\SetCreditLimit;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\ConsentRegistry;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\GuardianPermission;
use DomainException;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-014 · FR-048 · FR-049 · FR-050 — nothing is deferred without a record of
| someone agreeing to owe.
|
| Four claims, each failing in its own direction:
|
|   · no recorded consent, no deferral — asserted at the BOOKING, not at the
|     reader: the ceiling is a column, and a test that only reads it back proves
|     the column was written, not that anything consults it;
|   · publishing a new version stops the deferral it was granted under (FR-049).
|     The ceiling is not zeroed, it is ignored — and starts counting again the
|     moment the student signs the new text;
|   · agreeing to one document is not agreeing to the other, in both directions
|     (FR-050);
|   · and nobody signs in somebody else's name. Every refusal is the SAME 403,
|     including the one for a uuid that matches nobody — two answers would make
|     the endpoint a directory.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // A deferring mode, or there is nothing for a consent to unlock: FR-014 pins
    // the floor at zero in PREPAID_CREDITS whatever anyone agreed to.
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::ManualCollection->value]);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->balance = billingBalance($this->workspace, $this->student, $this->course)->refresh();
});

/** The signature the screen sends: the student, for themselves. */
function signTerms(?User $student = null, ConsentDocument $document = ConsentDocument::DeferredPaymentTerms): TermsConsent
{
    $test = test();

    return app(RecordTermsConsent::class)->handle(
        $student ?? $test->student,
        $student ?? $test->student,
        $document,
        '203.0.113.10',
        'PHPUnit',
    );
}

/**
 * One credit consumed, the way a delivered session consumes it.
 *
 * `enforceFloor: false` on purpose — that is how the charge posts, because the
 * floor guards the BOOKING and a session already taught is a debt whether or not
 * it fits.
 */
function consumeCredit(CreditBalance $balance): void
{
    app(CreditLedger::class)->post(new CreditMovement(
        balance: $balance,
        type: CreditTransactionType::Consume,
        credits: -1,
        sourceType: 'test_consume',
        sourceId: 1,
    ));
}

/** Whether a seat can actually be taken on this balance right now. */
function canBookOnCredit(): bool
{
    $session = billableSession(test()->workspace, test()->owner, test()->course);

    try {
        app(BookSeat::class)->handle($session->refresh(), test()->student);

        return true;
    } catch (DomainException) {
        return false;
    }
}

// FR-048 · SC-014 — the gate ---------------------------------------------------

it('defers nothing until the terms are accepted, and defers on the booking path once they are', function (): void {
    // Zero credits, no consent: the ceiling was never granted, so there is no
    // room to book into.
    expect($this->balance->refresh()->credit_limit_credits)->toBe(0)
        ->and(canBookOnCredit())->toBeFalse()
        ->and(SessionBooking::query()->withoutWorkspaceScope()->count())->toBe(0);

    signTerms();

    // Q-9's ١, granted by the signature and by nothing else.
    expect($this->balance->refresh()->credit_limit_credits)->toBe(1)
        ->and(canBookOnCredit())->toBeTrue()
        ->and(SessionBooking::query()->withoutWorkspaceScope()->count())->toBe(1);

    // And it is one session of room, not an open account: the debt appears when
    // the session is delivered, and at −1 the floor is reached.
    consumeCredit($this->balance);

    expect($this->balance->refresh()->remaining_credits)->toBe(-1)
        ->and(canBookOnCredit())->toBeFalse();
});

it('records who, when, from where and against which version', function (): void {
    $consent = signTerms();

    expect($consent->user_id)->toBe($this->student->getKey())
        ->and($consent->student_user_id)->toBe($this->student->getKey())
        ->and($consent->ip_address)->toBe('203.0.113.10')
        ->and($consent->user_agent)->toBe('PHPUnit')
        ->and($consent->consented_at)->not->toBeNull()
        ->and($consent->version)
        ->toBe(app(ConsentRegistry::class)->currentVersion(ConsentDocument::DeferredPaymentTerms));
});

it('refuses the platform its manual exception before any consent exists (FR-048)', function (): void {
    expect(fn () => app(SetCreditLimit::class)->handle($this->balance, 2, 'استثناء يدوي'))
        ->toThrow(DomainException::class);

    signTerms();

    expect(app(SetCreditLimit::class)->handle($this->balance->refresh(), 2, 'استثناء يدوي'))->toBe(2);
});

// FR-049 — the version ---------------------------------------------------------

it('stops deferring the moment new terms are published, and resumes when they are signed', function (): void {
    signTerms();

    expect(canBookOnCredit())->toBeTrue();

    // A second balance to book against, since the first ceiling is now spent.
    $this->balance->refresh();

    PlatformSettings::set('consents.versions.deferred_payment_terms', '2.0');

    // ⚠️ THE CEILING IS UNTOUCHED AND THE FLOOR IS ZERO. Asserted together
    // deliberately: a version bump that zeroed the column would pass the booking
    // half of this and then need a second sweep to restore anything, and the
    // student's earned ceiling would be gone for good.
    expect($this->balance->refresh()->credit_limit_credits)->toBe(1)
        ->and(app(CreditLedger::class)->floorForBalance($this->balance))->toBe(0);

    signTerms();

    expect(app(CreditLedger::class)->floorForBalance($this->balance->refresh()))
        ->toBe(-1);
});

it('never lets an old acceptance stand in for the version in force', function (): void {
    signTerms();

    PlatformSettings::set('consents.versions.deferred_payment_terms', '2.0');

    expect(app(ConsentRegistry::class)->has($this->student, ConsentDocument::DeferredPaymentTerms))->toBeFalse()
        // The row is still there — it is evidence of what was agreed and when,
        // and deleting it on a republish would destroy the record this whole
        // feature exists to keep.
        ->and(TermsConsent::query()->where('student_user_id', $this->student->getKey())->count())->toBe(1);
});

// FR-050 — one consent is not the other ---------------------------------------

it('does not let either consent stand in for the other', function (): void {
    signTerms(document: ConsentDocument::DataProcessing);

    $registry = app(ConsentRegistry::class);

    expect($registry->has($this->student, ConsentDocument::DataProcessing))->toBeTrue()
        ->and($registry->has($this->student, ConsentDocument::DeferredPaymentTerms))->toBeFalse()
        // And it unlocks nothing: agreeing to have your data processed is not
        // agreeing to owe money.
        ->and($this->balance->refresh()->credit_limit_credits)->toBe(0)
        ->and(canBookOnCredit())->toBeFalse();

    signTerms();

    // The mirror image. Both are now recorded, and each answers only for itself.
    expect($registry->has($this->student, ConsentDocument::DataProcessing))->toBeTrue()
        ->and($registry->has($this->student, ConsentDocument::DeferredPaymentTerms))->toBeTrue();
});

// T151 — nobody signs in somebody else's name ---------------------------------

it('refuses a signature for a student who is not this signer’s child', function (): void {
    $stranger = User::factory()->create();

    Sanctum::actingAs($stranger);

    $refusal = $this->postJson('/api/v1/billing/consents', [
        'document' => ConsentDocument::DeferredPaymentTerms->value,
        'student' => $this->student->uuid,
    ]);

    $refusal->assertForbidden();

    // ⚠️ AND THE SAME ANSWER FOR A UUID THAT IS NOBODY. Status AND body, because
    // a different message is a different answer: post uuids until one of them
    // reads differently and the endpoint has told you who exists.
    $unknown = $this->postJson('/api/v1/billing/consents', [
        'document' => ConsentDocument::DeferredPaymentTerms->value,
        'student' => '00000000-0000-4000-8000-000000000000',
    ]);

    // The MESSAGE, not the whole body: with debug on, the payload carries a
    // stack trace whose line numbers differ because the two calls are on
    // different lines of this file. What must not differ is what it says.
    expect($unknown->status())->toBe($refusal->status())
        ->and($unknown->json('message'))->toBe($refusal->json('message'))
        ->and(TermsConsent::query()->count())->toBe(0);
});

it('refuses a guardian who holds the relation but not the payments permission', function (): void {
    $guardian = User::factory()->create();

    ParentStudentRelation::factory()
        ->withPermissions([GuardianPermission::Attendance])
        ->create([
            'guardian_user_id' => $guardian->getKey(),
            'student_user_id' => $this->student->getKey(),
        ]);

    Sanctum::actingAs($guardian);

    // The right relation with the wrong consent. A guardian entitled to hear
    // about absences has no business committing this child to a debt.
    $this->postJson('/api/v1/billing/consents', [
        'document' => ConsentDocument::DeferredPaymentTerms->value,
        'student' => $this->student->uuid,
    ])->assertForbidden();

    expect(TermsConsent::query()->count())->toBe(0);
});

it('lets an authorised guardian sign for their child, and records both people', function (): void {
    $guardian = User::factory()->create();

    ParentStudentRelation::factory()
        ->withPermissions([GuardianPermission::Payments])
        ->create([
            'guardian_user_id' => $guardian->getKey(),
            'student_user_id' => $this->student->getKey(),
        ]);

    Sanctum::actingAs($guardian);

    $this->postJson('/api/v1/billing/consents', [
        'document' => ConsentDocument::DeferredPaymentTerms->value,
        'student' => $this->student->uuid,
    ])->assertCreated();

    $consent = TermsConsent::query()->firstOrFail();

    // Two columns because they are two people. Collapsing them would lose which
    // of them signed, which is the first question asked in a dispute.
    expect($consent->user_id)->toBe($guardian->getKey())
        ->and($consent->student_user_id)->toBe($this->student->getKey())
        ->and($this->balance->refresh()->credit_limit_credits)->toBe(1);
});

// The endpoint -----------------------------------------------------------------

it('reports what is outstanding and stamps the version itself', function (): void {
    Sanctum::actingAs($this->student);

    $this->getJson('/api/v1/billing/consents')
        ->assertOk()
        ->assertJsonPath('data.0.document', ConsentDocument::DeferredPaymentTerms->value)
        ->assertJsonPath('data.0.consented_at', null);

    // A version the client made up. Ignored rather than refused: it is not an
    // input at all, and accepting one would let a client agree to superseded
    // terms for ever.
    $this->postJson('/api/v1/billing/consents', [
        'document' => ConsentDocument::DeferredPaymentTerms->value,
        'version' => '0.1',
    ])->assertCreated();

    expect(TermsConsent::query()->firstOrFail()->version)
        ->toBe(app(ConsentRegistry::class)->currentVersion(ConsentDocument::DeferredPaymentTerms));

    $this->getJson('/api/v1/billing/consents')
        ->assertOk()
        ->assertJsonPath('data.0.version', app(ConsentRegistry::class)->currentVersion(ConsentDocument::DeferredPaymentTerms))
        ->assertJsonPath('data.0.consented_at', fn (?string $at): bool => $at !== null);
});

it('refuses a document nobody defined', function (): void {
    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/billing/consents', ['document' => 'whatever_terms'])
        ->assertStatus(422);
});

// The opening ceiling, and the two moments it is granted ----------------------

it('opens a balance created after the consent at the same ceiling', function (): void {
    signTerms();

    $second = courseWithRate((int) $this->workspace->getKey());

    // ⚠️ WITHOUT THIS THE GRANT REACHES ONLY THE BALANCES THAT EXISTED WHEN THEY
    // SIGNED. A student enrolling with a second teacher next month would sit at
    // zero until three on-time payments raised a ceiling from nothing.
    expect(app(CreditAccounts::class)->balanceFor($this->student, $second)->credit_limit_credits)->toBe(1);
});

it('does not restore a demoted ceiling when new terms are signed', function (): void {
    signTerms();

    // FR-040's demotion, by the hand that performs it.
    app(SetCreditLimit::class)->handle($this->balance->refresh(), 0, 'رصيد سالب لفترة طويلة.');

    PlatformSettings::set('consents.versions.deferred_payment_terms', '2.0');

    signTerms();

    // ⚠️ THE ONE THING A RE-SIGNATURE MUST NOT DO. The grant is a first-consent
    // event, not a state derived from `limit === 0 && consent` — derived, every
    // republish would hand back the ceiling FR-040 took away, and the demotion
    // would last until the next version.
    expect($this->balance->refresh()->credit_limit_credits)->toBe(0);
});

it('opens no ceiling at all in a workspace that defers nothing', function (): void {
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::PrepaidCredits->value]);

    signTerms();

    // The consent is real; the mode is what has no room in it (FR-014).
    expect($this->balance->refresh()->credit_limit_credits)->toBe(0);
});
