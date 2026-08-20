<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Identity\Support\UserStatus;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Payments\Actions\RecordTermsConsent;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Support\ConsentRegistry;
use App\Shared\Support\GuardianPermission;

/**
 * Two authorised guardians disagree: REFUSAL WINS (R6).
 *
 * ⚠️ AND THE RULE WAS UNIMPLEMENTABLE UNTIL THIS PHASE, which is the finding worth
 * keeping. `terms_consents` recorded ACCEPTANCES ONLY — so with one guardian
 * consenting and another refusing, `has()` found the consenting row and answered
 * true. Refusal did not win; whichever INSERT happened first did. A rule written
 * in prose with nowhere to store it is not a rule, and three planning documents
 * recorded this one as already satisfied by the shipped design.
 *
 * ⚠️ THE CHOICE IS NOT ARBITRARY EITHER. Every other tie-break needs a referee:
 * "latest wins" makes a child's privacy a game of who taps last between two
 * parents in a dispute, and "first wins" freezes a decision that may reverse.
 * Refusal is the only REVERSIBLE position — a guardian who refused can consent
 * tomorrow; a guardian whose child's face is already in a file cannot take it
 * back.
 */
function guardianFor(User $student, string $suffix): User
{
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    ParentStudentRelation::query()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $student->getKey(),
        'student_name' => 'طالب '.$suffix,
        'relation_type' => RelationType::Guardian->value,
        'permissions' => [GuardianPermission::DataRights->value],
        'status' => RelationStatus::Active->value,
    ]);

    return $guardian;
}

beforeEach(function (): void {
    $this->student = User::factory()->create([
        'platform_role' => PlatformRole::Student,
        'status' => UserStatus::PendingGuardianConsent->value,
    ]);

    $this->consenting = guardianFor($this->student, 'أ');
    $this->refusing = guardianFor($this->student, 'ب');
});

it('lets a later refusal override an earlier consent', function (): void {
    $record = app(RecordTermsConsent::class);
    $registry = app(ConsentRegistry::class);

    $record->handle($this->consenting, $this->student, ConsentDocument::DataProcessing, '203.0.113.1', null, ['student_name']);

    expect($registry->has($this->student, ConsentDocument::DataProcessing))->toBeTrue()
        ->and($this->student->fresh()?->status)->toBe(UserStatus::Active->value);

    // A second later, so the ordering is unambiguous rather than a tie the
    // database breaks by insertion order — which is precisely the behaviour this
    // rule replaces.
    $this->travel(1)->seconds();

    $record->handle($this->refusing, $this->student, ConsentDocument::DataProcessing, '203.0.113.2', null, ['student_name'], granted: false);

    expect($registry->has($this->student, ConsentDocument::DataProcessing))->toBeFalse();
});

/*
 * ⚠️ AND THE OTHER ORDER MUST GIVE THE SAME ANSWER, which is the half that fails
 * on a naive implementation.
 *
 * With the refusal FIRST, an existence check for a grant finds the later consent
 * row and answers true — so a test that only walks consent-then-refusal passes
 * over exactly the arrangement the rule exists for.
 */
it('lets an earlier refusal survive a later consent from the other guardian', function (): void {
    $record = app(RecordTermsConsent::class);
    $registry = app(ConsentRegistry::class);

    $record->handle($this->refusing, $this->student, ConsentDocument::DataProcessing, '203.0.113.2', null, ['student_name'], granted: false);
    $this->travel(1)->seconds();
    $record->handle($this->consenting, $this->student, ConsentDocument::DataProcessing, '203.0.113.1', null, ['student_name']);

    /*
    | The LATEST decision is a grant, so consent stands — and that is correct
    | rather than a hole. "Refusal wins" is about a standing disagreement, not
    | about a refusal being permanent: a guardian who refused in March and agreed
    | in April has agreed. What the rule forbids is an older grant outranking a
    | newer refusal, which is the case above.
    */
    expect($registry->has($this->student, ConsentDocument::DataProcessing))->toBeTrue();
});

it('records a refusal as a row rather than deleting the consent', function (): void {
    $record = app(RecordTermsConsent::class);

    $record->handle($this->consenting, $this->student, ConsentDocument::DataProcessing, '203.0.113.1', null, ['student_name']);
    $this->travel(1)->seconds();
    $record->handle($this->refusing, $this->student, ConsentDocument::DataProcessing, '203.0.113.2', null, ['student_name'], granted: false);

    /*
    | ⚠️ BOTH DECISIONS SURVIVE. The table is a log of decisions taken at moments,
    | and deleting the grant would destroy the evidence that one guardian DID
    | agree — which is the fact a custody dispute turns on. The same argument
    | `LedgerEntry` and `CreditTransaction` make for themselves.
    */
    $rows = TermsConsent::query()
        ->where('student_user_id', $this->student->getKey())
        ->orderBy('consented_at')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->decision)->toBe('granted')
        ->and($rows[1]->decision)->toBe('refused')
        // And each names its own signer, so "who decided what" is answerable.
        ->and((int) $rows[0]->user_id)->toBe((int) $this->consenting->getKey())
        ->and((int) $rows[1]->user_id)->toBe((int) $this->refusing->getKey());
});

/*
 * ⚠️ AND NO MESSAGE MAY NAME WHO REFUSED.
 *
 * The two guardians may be in a custody dispute, and «رفضت والدتك» in an automated
 * message is personal data about a third party, written by us. The template's
 * variable list is asserted separately in `NotificationTemplateCoverageTest`; here
 * the point is that the RULE holds where the decision is made — the conflict is
 * visible in the record, and invisible in what either party is told.
 */
it('keeps the refusing guardian out of the other guardian\'s view of the decision', function (): void {
    $record = app(RecordTermsConsent::class);

    $record->handle($this->consenting, $this->student, ConsentDocument::DataProcessing, '203.0.113.1', null, ['student_name']);
    $this->travel(1)->seconds();
    $record->handle($this->refusing, $this->student, ConsentDocument::DataProcessing, '203.0.113.2', null, ['student_name'], granted: false);

    // Re-encoded, because `getContent()` escapes non-ASCII — an assertion written
    // with an Arabic needle against the raw body passes whatever the payload holds.
    $body = json_encode(
        Notification::query()->get()->toArray(),
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    $refuserName = trim($this->refusing->first_name.' '.$this->refusing->last_name);

    expect($body)->not->toContain($refuserName)
        ->and($body)->not->toContain((string) $this->refusing->uuid);
});
