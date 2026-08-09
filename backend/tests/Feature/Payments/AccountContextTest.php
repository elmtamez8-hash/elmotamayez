<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Exceptions\InsufficientCreditsException;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\StudentCreditAccount;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\CreditLedger;

/*
| SC-018 · SC-019 — one account, many balances, and the course is the context.
|
| Q-7 fixes the session price on the COURSE, so credits are bought for a course
| and spent on its sessions. Summing across courses is not a compressed answer
| but a wrong one: +10 maths and −6 physics reads as +4 and unblocked, while the
| design withholds per course precisely so the paid-up course stays open.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->accounts = app(CreditAccounts::class);
    $this->ledger = app(CreditLedger::class);
});

it('gives a student one account and one balance per course across three teachers', function (): void {
    foreach (['رياضيات', 'فيزياء', 'كيمياء'] as $name) {
        [$workspace] = $this->createWorkspaceWithOwner(['name' => $name]);
        billingBalance($workspace, $this->student);
    }

    expect(StudentCreditAccount::query()->where('user_id', $this->student->getKey())->count())->toBe(1)
        ->and($this->accounts->balancesFor($this->student))->toHaveCount(3);
});

it('gives a second course with the same teacher its own balance', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();

    $first = billingBalance($workspace, $this->student);
    $second = billingBalance($workspace, $this->student, billingCourse($workspace));

    expect($first->getKey())->not->toBe($second->getKey())
        ->and($this->accounts->balancesFor($this->student))->toHaveCount(2)
        ->and($first->student_credit_account_id)->toBe($second->student_credit_account_id);
});

it('refuses to spend one course\'s credits on another course', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();

    $maths = billingBalance($workspace, $this->student);
    $physics = billingBalance($workspace, $this->student, billingCourse($workspace));

    grantCredits($maths, 4, 'maths');

    // The physics balance is empty and stays empty. There is no path that reads
    // the maths credits from here — the balance IS the scope of the money.
    expect(fn () => $this->ledger->post(new CreditMovement(
        balance: $physics,
        type: CreditTransactionType::Consume,
        credits: -1,
        sourceType: 'seat',
        sourceId: 1,
        enforceFloor: true,
    )))->toThrow(InsufficientCreditsException::class);

    expect($maths->refresh()->remaining_credits)->toBe(4)
        ->and($physics->refresh()->remaining_credits)->toBe(0);
});

it('reads zero for a student who has never had an account', function (): void {
    $newcomer = User::factory()->create();

    // US1/1 — no row is created when a balance is merely read. Zero is the right
    // answer and needs nothing written to say it.
    expect($this->accounts->balancesFor($newcomer))->toHaveCount(0)
        ->and(StudentCreditAccount::query()->where('user_id', $newcomer->getKey())->exists())->toBeFalse();
});

it('creates the account once even when two balances are asked for at the same time', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();

    $courseA = billingCourse($workspace);
    $courseB = billingCourse($workspace);

    $this->accounts->balanceFor($this->student, $courseA);
    $this->accounts->balanceFor($this->student, $courseB);

    expect(StudentCreditAccount::query()->where('user_id', $this->student->getKey())->count())->toBe(1)
        ->and(CreditBalance::query()->withoutWorkspaceScope()->count())->toBe(2);
});
