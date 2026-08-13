<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\AdjustCredits;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Events\AccessWithheld;
use App\Modules\Payments\Events\RefundIssued;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Tenancy\Support\Roles;
use DomainException;
use Illuminate\Support\Facades\Event;

/*
| FR-025 · quickstart 9ج — a refund takes money OUT, and the floor is the one
| place in this phase where that direction is refused.
|
| ⚠️ THE ASYMMETRY WITH DELIVERY IS THE DESIGN, not an oversight to tidy up. A
| session that was delivered is a debt whether or not the student can pay for it,
| so recording it must never be refused — `enforceFloor` defaults to false for
| exactly that reason. A refund is the opposite direction: money paid back
| against credits already consumed is a session the teacher delivered and would
| now go unpaid for.
|
| ⚠️ AND EIGHT REQUESTED AGAINST SIX HELD RETURNS SIX, IT DOES NOT FAIL. Refusing
| the whole request would hold hostage the credits nobody disputes. The clamp
| decides the amount; the conditional UPDATE decides whether that amount is still
| true at the moment of the write.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->balance = billingBalance($this->workspace, $this->student, $this->course);

    grantCredits($this->balance, 8, 'purchase-fixture');
    consumeCredits($this->balance, 2);

    $this->balance->refresh();
});

it('returns what is left and not what was asked for', function (): void {
    expect($this->balance->remaining_credits)->toBe(6);

    $entry = app(AdjustCredits::class)->handle(
        balance: $this->balance,
        type: CreditTransactionType::Refund,
        credits: -8,
        reason: 'الطالب انسحب من الكورس',
        idempotencyKey: 'refund-1',
        performedBy: $this->owner,
    );

    // Six, because two sessions were delivered and the teacher has earned their
    // fee for them (spec 014). The balance lands exactly at zero, never below.
    expect($entry?->credits)->toBe(-6)
        ->and($this->balance->refresh()->remaining_credits)->toBe(0);
});

it('will not refund a balance that has nothing left to give back', function (): void {
    consumeCredits($this->balance, 6, 'test_consume_rest');

    expect($this->balance->refresh()->remaining_credits)->toBe(0);

    // Distinct from the "zero credits" refusal, which reads as a caller mistake:
    // this is a fact about the account, and the operator needs to know which.
    expect(fn () => app(AdjustCredits::class)->handle(
        balance: $this->balance,
        type: CreditTransactionType::Refund,
        credits: -3,
        reason: 'الطالب انسحب',
        idempotencyKey: 'refund-empty',
    ))->toThrow(DomainException::class);
});

it('demands a reason and announces the refund', function (): void {
    Event::fake([RefundIssued::class]);

    expect(fn () => app(AdjustCredits::class)->handle(
        balance: $this->balance,
        type: CreditTransactionType::Refund,
        credits: -1,
        reason: '   ',
        idempotencyKey: 'refund-no-reason',
    ))->toThrow(DomainException::class);

    app(AdjustCredits::class)->handle(
        balance: $this->balance,
        type: CreditTransactionType::Refund,
        credits: -1,
        reason: 'خطأ في التحويل',
        idempotencyKey: 'refund-reasoned',
    );

    Event::assertDispatched(RefundIssued::class);
});

it('leaves the teacher ledger untouched', function (): void {
    app(AdjustCredits::class)->handle(
        balance: $this->balance,
        type: CreditTransactionType::Refund,
        credits: -6,
        reason: 'الطالب انسحب',
        idempotencyKey: 'refund-2',
    );

    // The two contexts share no key and no query, and a refund is the case that
    // most tempts a bridge: the money came back, so surely the teacher's pay
    // moves? It does not. What the teacher earned was earned on delivery.
    expect(LedgerEntry::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('re-withholds a student whose refund took back what unblocked them', function (): void {
    // A deferring workspace, so the balance has somewhere to fall to and the
    // withholding predicate has something to say.
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::ManualCollection->value]);

    Event::fake([AccessWithheld::class]);

    app(AdjustCredits::class)->handle(
        balance: $this->balance,
        type: CreditTransactionType::Refund,
        credits: -6,
        reason: 'استرداد جزئي بعد سداد أغلق مستحقاً',
        idempotencyKey: 'refund-partial',
    );

    /*
    | ⚠️ THE EDGE CASE THAT HAD NO CODE BEHIND IT: "a partial refund of a payment
    | that closed an outstanding balance — the debt is partly reopened and the
    | withholding re-evaluated".
    |
    | Nothing re-evaluates it, and nothing needs to: `is_withheld` is derived from
    | five inputs and stored as none of them, so the next booking simply answers
    | differently. What DOES need writing is the telling — otherwise the student
    | learns they are blocked by being refused (FR-033).
    */
    Event::assertDispatched(AccessWithheld::class);
});
