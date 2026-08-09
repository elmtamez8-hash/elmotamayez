<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
| FR-009ج · FR-021د — one account, split by course, in credits alone.
|
| The money check is the load-bearing one. A credit's price is the teacher's
| approved settlement rate plus two platform constants, so a student who is shown
| what they paid can solve for the constants from two package sizes and then read
| every other teacher's rate off any published total. Keeping money out of this
| payload is what stops the settlement side leaking through arithmetic — see
| contracts/api.md §2ب.
*/

beforeEach(function (): void {
    [$this->maths] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية الرياضيات']);
    [$this->physics] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية الفيزياء']);

    $this->student = User::factory()->create();
});

it('returns one entry per course across every teacher', function (): void {
    $mathsBalance = billingBalance($this->maths, $this->student);
    billingBalance($this->physics, $this->student);

    grantCredits($mathsBalance, 8, 'maths');

    Sanctum::actingAs($this->student);

    $payload = $this->getJson('/api/v1/billing/balance')->assertOk()->json();

    expect($payload)->toHaveCount(2);

    $names = array_column(array_column($payload, 'course'), 'teacher_name');

    sort($names);

    expect($names)->toBe(['أكاديمية الرياضيات', 'أكاديمية الفيزياء']);
});

it('carries credits and never a figure of money', function (): void {
    $balance = billingBalance($this->maths, $this->student);
    grantCredits($balance, 8, 'money-check');

    Sanctum::actingAs($this->student);

    $payload = $this->getJson('/api/v1/billing/balance')->assertOk()->json('0');

    expect($payload['remaining_credits'])->toBe(8)
        ->and($payload['purchased_credits'])->toBe(8)
        ->and($payload['consumed_credits'])->toBe(0);

    foreach (settlementPayloadKeys($payload) as $key) {
        expect($key)->not->toContain('minor')
            ->and($key)->not->toContain('price')
            ->and($key)->not->toContain('amount')
            ->and($key)->not->toContain('rate');
    }
});

it('reports withheld for a balance with nothing left', function (): void {
    $empty = billingBalance($this->maths, $this->student);
    $funded = billingBalance($this->physics, $this->student);

    grantCredits($funded, 2, 'funded');

    Sanctum::actingAs($this->student);

    $byWorkspace = collect($this->getJson('/api/v1/billing/balance')->assertOk()->json())
        ->keyBy(fn (array $row): string => $row['course']['teacher_name']);

    // The default state — zero credits, zero limit — is withheld. The paid-up
    // course beside it stays open, which is the whole reason the balance is per
    // course rather than per student.
    expect($byWorkspace['أكاديمية الرياضيات']['is_withheld'])->toBeTrue()
        ->and($byWorkspace['أكاديمية الفيزياء']['is_withheld'])->toBeFalse()
        ->and($empty->refresh()->remaining_credits)->toBe(0)
        ->and($funded->refresh()->remaining_credits)->toBe(2);
});

it('returns an empty list for a student who has never had an account', function (): void {
    Sanctum::actingAs(User::factory()->create());

    expect($this->getJson('/api/v1/billing/balance')->assertOk()->json())->toBe([]);
});

it('lists this student\'s own ledger and nobody else\'s', function (): void {
    $mine = billingBalance($this->maths, $this->student);
    grantCredits($mine, 8, 'mine');

    $stranger = User::factory()->create();
    grantCredits(billingBalance($this->maths, $stranger), 16, 'theirs');

    Sanctum::actingAs($this->student);

    $entries = $this->getJson('/api/v1/billing/transactions')->assertOk()->json('data');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['credits'])->toBe(8);
});

it('filters the ledger by course', function (): void {
    $mathsBalance = billingBalance($this->maths, $this->student);
    $physicsBalance = billingBalance($this->physics, $this->student);

    grantCredits($mathsBalance, 8, 'maths');
    grantCredits($physicsBalance, 4, 'physics');

    Sanctum::actingAs($this->student);

    $entries = $this->getJson('/api/v1/billing/transactions?course='.$mathsBalance->course->uuid)
        ->assertOk()->json('data');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['credits'])->toBe(8);
});

it('refuses a guest', function (): void {
    $this->getJson('/api/v1/billing/balance')->assertUnauthorized();
});
