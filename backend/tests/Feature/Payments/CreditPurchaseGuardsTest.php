<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| Spec 031 · the guards on the credit doors that were missing, not the feature.
|
| Three defects that shipped before this phase and are reachable today by an
| ordinary buyer. None of them needs a guardian to exist.
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية الفيزياء']);

    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    PlatformSettings::set('billing.operating_fee_minor.individual', 500);
    PlatformSettings::set('billing.gateway_fee_bps', 0);
    PlatformSettings::set('billing.gateway_fixed_fee_minor', 0);

    $this->package = CreditPackage::query()->create([
        'name' => 'أربع حصص',
        'credits' => 4,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
        'sort_order' => 1,
    ]);
});

/*
| ⛔ A COURSE THAT IS NOT PUBLISHED SOLD CREDITS.
|
| Nothing on this path read `courses.status` — not `StopSellingGuard`, not
| `isPartyTo`, not the pricing Action, not the controller — and the column
| defaults to `draft`. So a student who is a member of the workspace could price
| and buy a package on a course the teacher has never released.
|
| ⚠️ The condition lives in `StopSellingGuard` and nowhere else, which is what
| makes both doors answer together: pricing turns a refusal into an empty list,
| purchase turns it into a sentence.
*/
it('sells nothing on a course that is not published', function (): void {
    $this->course->forceFill(['status' => 'draft'])->save();

    Sanctum::actingAs($this->student);

    $this->getJson('/api/v1/billing/packages?course='.$this->course->uuid)
        ->assertOk()
        // Resources are unwrapped in this app, so the empty list is the root.
        ->assertJsonCount(0);

    $this->postJson('/api/v1/billing/purchases', [
        'course' => $this->course->uuid,
        'package' => $this->package->uuid,
    ])->assertStatus(422);

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

/*
| ⛔ AND A COURSE THAT DOES NOT EXIST ANSWERED DIFFERENTLY FROM ONE THE CALLER MAY
| NOT BUY. `firstOrFail()` was a 404 while a real course they are not party to is
| a 403 — so any signed-in caller could tell a live uuid from a dead one. Course
| uuids are v4, so nothing is enumerable; what leaked is CONFIRMATION of a uuid
| obtained elsewhere. It is the oracle `PurchaseCreditsRequest` refuses an
| `exists:` rule to avoid, one field along.
*/
it('answers a missing course exactly as it answers a forbidden one', function (): void {
    $stranger = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    [$other] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
    $foreign = courseWithRate((int) $other->getKey(), 5000);

    Sanctum::actingAs($stranger);

    $missing = $this->postJson('/api/v1/billing/purchases', [
        'course' => (string) Str::uuid(),
        'package' => $this->package->uuid,
    ]);

    $forbidden = $this->postJson('/api/v1/billing/purchases', [
        'course' => $foreign->uuid,
        'package' => $this->package->uuid,
    ]);

    expect($missing->status())->toBe($forbidden->status())
        ->and($missing->json('message'))->toBe($forbidden->json('message'));
});

/*
| ⛔ THE PROXY WHO CREATED AN ORDER COULD NOT PAY IT — AND THE SCREEN OFFERED THEM
| THE BUTTON.
|
| `OrderPolicy::pay()` asked `user_id === caller` with no `granted_by` branch; its
| docblock was written before spec 024 gave orders a proxy at all. Meanwhile
| `OrderResource` sets `is_mine` true for the grantor and `/orders` draws «ادفع
| الآن» on `is_mine`. Both halves live, in opposite directions, since 029.
*/
it('lets the person who created an order on somebody else behalf pay it', function (): void {
    $officer = makePlatformStaff('finance-admin');

    $order = Order::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'amount' => '100.00',
        'currency' => 'QAR',
        'status' => 'pending',
        'provider' => 'manual',
    ]);

    $order->forceFill(['granted_by' => $officer->getKey()])->save();

    expect($officer->can('pay', $order))->toBeTrue()
        // The owner keeps it, and a stranger still does not have it.
        ->and($this->student->can('pay', $order))->toBeTrue()
        ->and($this->teacher->can('pay', $order))->toBeFalse();
});

/*
| The receipt door already carried the proxy branch (029). Asserted beside the
| payment one so the pair cannot drift apart again: they are the same question
| about the same row, and only one of them had been answered.
*/
it('lets that same person upload the receipt', function (): void {
    Sanctum::actingAs($this->student);

    $uuid = $this->postJson('/api/v1/billing/purchases', [
        'course' => $this->course->uuid,
        'package' => $this->package->uuid,
    ])->assertCreated()->json('order');

    $officer = makePlatformStaff('finance-admin');

    Order::query()->withoutWorkspaceScope()->where('uuid', $uuid)
        ->update(['granted_by' => $officer->getKey()]);

    Sanctum::actingAs($officer);

    $this->postJson("/api/v1/orders/{$uuid}/receipt", [
        'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        'method' => 'bank_transfer',
    ])->assertOk();
});
