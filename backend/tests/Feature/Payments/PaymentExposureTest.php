<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\PaymentFieldAllowlist;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| SC-012 — no secret and no payment-method detail in any response of this phase.
|
| ⚠️ AN ENUMERATED LIST OF PAYLOADS, WALKED. "Any response" without a list of
| responses to walk is not a check; it is a sentence in a document. Every payload
| this phase serves is named below and every one of them is fetched, so a payload
| that grows a field gets caught — and a payload ADDED LATER and not added here
| does not, which is the limit `PaymentFieldAllowlist`'s header states out loud.
|
| ⚠️ AND THE FIXTURE PLANTS THE LEAK. A test over clean rows proves that clean
| data stays clean. The transaction below is stored with a card number, a CVV and
| a signing secret in its `payload` — the shape a real provider echo has — so the
| assertion is that none of it reaches a screen, rather than that none of it
| existed.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'kind' => OrderKind::Credits,
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]);

    $this->payment = PaymentTransaction::create([
        'workspace_id' => $this->workspace->getKey(),
        'order_id' => $this->order->getKey(),
        'provider' => 'manual',
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'status' => PaymentStatus::Captured,
        'method' => PaymentMethod::Gateway,
        'reference' => 'REF-EXPOSURE',
        // 4242 4242 4242 4242 passes Luhn, which is what makes it a card number
        // rather than a long id. Stored raw here on purpose: this is what a
        // payload looks like before anything scrubs it.
        'payload' => [
            'card' => '4242424242424242',
            'cvv' => '123',
            'signature' => 'sk_live_not_a_real_secret',
            'note' => 'paid with 4242 4242 4242 4242',
        ],
    ]);

    $this->platform = User::factory()->create(['is_super_admin' => true]);
});

it('leaks nothing on any payload this phase serves', function (): void {
    $window = 'from='.now()->subWeek()->toDateString().'&to='.now()->addDay()->toDateString();

    $payloads = [];

    // The platform's four.
    Sanctum::actingAs($this->platform);

    $payloads['collection'] = $this->getJson('/api/v1/admin/payments/collection?'.$window)->assertOk()->json();
    $payloads['audit'] = $this->getJson('/api/v1/admin/payments/audit')->assertOk()->json();
    $payloads['chain'] = $this->getJson("/api/v1/admin/payments/audit/{$this->payment->uuid}")->assertOk()->json();
    $payloads['reconciliation'] = $this->getJson('/api/v1/admin/payments/reconciliation')->assertOk()->json();

    // And the payer's own read of their payment, which is the payload closest to
    // the provider of all of them.
    Sanctum::actingAs($this->student);
    $this->setCurrentWorkspace($this->workspace, $this->student);

    $payloads['payment'] = $this->getJson("/api/v1/payments/{$this->payment->uuid}")->assertOk()->json();

    $leaks = [];

    foreach ($payloads as $name => $payload) {
        $found = PaymentFieldAllowlist::leaks($payload);

        if ($found !== []) {
            $leaks[$name] = $found;
        }
    }

    // The whole map rather than one assertion per payload: a failure then names
    // which screen leaked and what, in one line.
    expect($leaks)->toBe([]);
});

it('carries no teacher settlement rate on any payload, which is scenario 6', function (): void {
    $window = 'from='.now()->subWeek()->toDateString().'&to='.now()->addDay()->toDateString();

    Sanctum::actingAs($this->platform);

    // FR-035, checked rather than asserted in a comment. The rate IS derivable
    // from the credits and the two platform fees — `StudentBalanceAllowlist`
    // makes the same admission about the teacher's side — but a derivation is a
    // step somebody takes on purpose, and a named column is one they copy into a
    // spreadsheet without noticing what it is.
    $payloads = [
        json_encode($this->getJson('/api/v1/admin/payments/collection?'.$window)->assertOk()->json()),
        json_encode($this->getJson("/api/v1/admin/payments/audit/{$this->payment->uuid}")->assertOk()->json()),
        $this->get('/api/v1/admin/payments/collection/export?'.$window)->assertOk()->streamedContent(),
    ];

    foreach ($payloads as $payload) {
        expect((string) $payload)->not->toContain('teacher_rate')
            ->and((string) $payload)->not->toContain('settlement');
    }
});

it('sends exactly the fields the allowlist names, and no more', function (): void {
    Sanctum::actingAs($this->platform);

    $row = $this->getJson('/api/v1/admin/payments/collection?from='.now()->subWeek()->toDateString().'&to='.now()->addDay()->toDateString())
        ->assertOk()
        ->json('rows.data.0');

    // The other half of the guard: `leaks()` catches a name that says secret,
    // this catches a field nobody meant to publish — a payer's phone number, a
    // provider's raw response — which no forbidden-word list would recognise.
    expect(array_keys($row))->toEqualCanonicalizing(PaymentFieldAllowlist::collectionRow());
});

it('leaks nothing into the export either, which is the copy that leaves the building', function (): void {
    Sanctum::actingAs($this->platform);

    $csv = $this->get('/api/v1/admin/payments/collection/export?from='.now()->subWeek()->toDateString().'&to='.now()->addDay()->toDateString())
        ->assertOk()
        ->streamedContent();

    // A file is not a tree, so the field walk cannot see it — the check is that
    // the words themselves are absent. FR-034 names the export explicitly, and a
    // report guarded on screen and unguarded as a file is guarded nowhere.
    expect($csv)->not->toContain('4242424242424242')
        ->and($csv)->not->toContain('sk_live_not_a_real_secret')
        ->and($csv)->not->toContain('cvv');
});

it('sees a leak when there is one, which is the only proof the walk works', function (): void {
    // The failing direction. Without it the assertions above pass identically
    // against a `leaks()` that returns an empty array unconditionally — the
    // failure mode every guard of this shape has.
    expect(PaymentFieldAllowlist::leaks(['data' => ['note' => '4242 4242 4242 4242']]))
        ->toBe(['card-shaped value: data.note'])
        ->and(PaymentFieldAllowlist::leaks(['data' => ['cardholder_name' => 'Ali']]))
        ->toBe(['forbidden key: data.cardholder_name'])
        // And an ordinary long number is not a card: an order id, a phone number
        // and a timestamp are all digit runs, and redacting those would empty
        // every payload this walk exists to protect.
        ->and(PaymentFieldAllowlist::leaks(['data' => ['order_ref' => '1234567890123']]))
        ->toBe([]);
});
