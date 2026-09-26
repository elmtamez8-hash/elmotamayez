<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| The audit list → one payment's chain, for an officer whose own workspace is
| elsewhere.
|
| ⚠️ THE DECISIONS AN AUDITOR OPENS ARE LOGGED ON THE ORDER, and the chain behind
| them (`/admin/payments/audit/{transaction}`) is addressed by the PAYMENT. So the
| list names the payment on an order row too (`payment_uuid`) — otherwise the rows
| worth clicking are exactly the rows with no link.
|
| ⚠️ AND EVERY SUBJECT WAS READ UNDER THE OFFICER'S OWN WORKSPACE. A bare
| `->with('subject')` answered `subject_uuid: null` for every decision in any
| other workspace — the list and the chain's trail alike. The fixture proves the
| context is the officer's workspace, not null, before it asks.
*/
beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية الدفعات']);
    [$this->officerWorkspace] = $this->createWorkspaceWithOwner(['name' => 'ورشة الموظف']);

    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->order = Order::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);

    $payment = fn (PaymentStatus $status, ?string $settledAt, string $reference): PaymentTransaction => PaymentTransaction::query()
        ->withoutWorkspaceScope()
        ->create([
            'workspace_id' => $this->workspace->getKey(),
            'order_id' => $this->order->getKey(),
            'provider' => 'manual',
            'amount_minor' => 9_000,
            'currency' => 'QAR',
            'status' => $status,
            'reference' => $reference,
            'settled_at' => $settledAt,
        ]);

    // The settled one first and a later abandoned retry: the link must name the
    // money that arrived, not merely the newest attempt.
    $this->settled = $payment(PaymentStatus::Captured, now()->subDay()->toDateTimeString(), 'REF-SETTLED');
    $this->retry = $payment(PaymentStatus::Pending, null, 'REF-RETRY');

    $this->officer = User::factory()->create(['is_super_admin' => true]);
    $this->officer->forceFill(['last_workspace_id' => $this->officerWorkspace->getKey()])->save();

    activity()->performedOn($this->order)->causedBy($this->officer)->log('approved');
    activity()->performedOn($this->settled)->log('payment.reversed');
    // A subject with no payment behind it at all.
    $package = CreditPackage::query()->create([
        'name' => 'حزمة',
        'credits' => 10,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
    ]);
    activity()->performedOn($package)->log('package.updated');

    Sanctum::actingAs($this->officer);
    app()->forgetInstance(WorkspaceContext::class);

    expect(app(WorkspaceContext::class)->id())->toBe((int) $this->officerWorkspace->getKey());
});

it('names the order and its settled payment on an order row from another workspace', function (): void {
    $rows = collect($this->getJson('/api/v1/admin/payments/audit')->assertOk()->json('data'));

    $approval = $rows->firstWhere('subject_type', 'order');

    expect($approval['subject_uuid'])->toBe($this->order->uuid)
        ->and($approval['payment_uuid'])->toBe($this->settled->uuid);

    expect($rows->firstWhere('subject_type', 'package')['payment_uuid'])->toBeNull();

    $reversal = $rows->firstWhere('subject_type', 'payment');

    expect($reversal['subject_uuid'])->toBe($this->settled->uuid)
        ->and($reversal['payment_uuid'])->toBe($this->settled->uuid);
});

it('opens the chain the link names, with its trail subjects intact', function (): void {
    $chain = $this->getJson("/api/v1/admin/payments/audit/{$this->settled->uuid}")->assertOk()->json('data');

    expect($chain['payment']['uuid'])->toBe($this->settled->uuid)
        ->and($chain['order_uuid'])->toBe($this->order->uuid)
        ->and(collect($chain['trail'])->pluck('subject_uuid')->all())
        ->toBe([$this->order->uuid, $this->settled->uuid]);
});
