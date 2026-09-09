<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

/*
| US3 — the packages a student may buy, and starting a purchase.
|
| Four separate claims, and they fail in four different directions:
|   · the price is a single total, and its components never reach a browser;
|   · a stranger gets 403, not an unpriced list — the total is invertible;
|   · no credit exists until the payment is approved (FR-018);
|   · the snapshot is written once and survives a later rate approval.
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية الرياضيات']);

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

function purchasePayload(Course $course, CreditPackage $package): array
{
    return ['course' => $course->uuid, 'package' => $package->uuid];
}

// The list ------------------------------------------------------------------

it('prices each package as one total, with no component on the wire', function (): void {
    Sanctum::actingAs($this->student);

    $offers = $this->getJson("/api/v1/billing/packages?course={$this->course->uuid}")
        ->assertOk()->json();

    expect($offers)->toHaveCount(1)
        // (5000 + 500) × 4, no gateway configured.
        ->and($offers[0]['total_minor'])->toBe(22_000)
        ->and($offers[0]['credits'])->toBe(4);

    // FR-021ج · FR-021ب — the teacher's rate is the INPUT to this number, and
    // the other two components are platform constants. Publishing any of them
    // publishes what every teacher on the platform is paid.
    foreach (settlementPayloadKeys($offers[0]) as $key) {
        expect($key)->not->toContain('rate')
            ->and($key)->not->toContain('fee')
            ->and($key)->not->toContain('teacher')
            ->and($key)->not->toContain('operating')
            ->and($key)->not->toContain('gateway');
    }
});

it('answers 403 to a stranger rather than a priced list', function (): void {
    // Not an empty list either: a total is `(rate + constants) × credits`, so two
    // package sizes solve for the constants and every other course's total then
    // inverts to its teacher's approved rate exactly.
    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/billing/packages?course={$this->course->uuid}")
        ->assertForbidden();
});

/*
| ⚠️ AND THE TEACHER IS NOT A STRANGER — THEY ARE WORSE.
|
| A stranger who reads two totals solves for the platform's two constants and
| stops there, because the third unknown is a rate they do not have. The teacher
| HAS one: their own, asked for by them and read on their own statement. Two
| package sizes therefore hand them the constants by subtraction, and from there
| every OTHER course's total inverts to its teacher's approved rate exactly.
|
| This is the one direction TeacherFieldAllowlist and StudentBalanceAllowlist
| cannot guard. Nothing here is a field on a teacher-facing payload — it is the
| STUDENT'S own screen, opened by the wrong person.
*/
it('answers 403 to the teacher whose course it is', function (): void {
    Sanctum::actingAs($this->teacher);

    $this->getJson("/api/v1/billing/packages?course={$this->course->uuid}")
        ->assertForbidden();
});

it('answers 403 to an assistant teacher in the same workspace', function (): void {
    Sanctum::actingAs($this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER));

    $this->getJson("/api/v1/billing/packages?course={$this->course->uuid}")
        ->assertForbidden();
});

it('answers 403 to a teacher who enrolled in their own course', function (): void {
    // The loophole that survives merely deleting the membership branch. An
    // enrolment is a row the teacher can cause to exist, so "is this person on
    // the teaching side" has to be asked FIRST and OVERRIDE the enrolment —
    // not merely fail to be one of the ways in.
    Enrollment::factory()->completed()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->teacher->getKey(),
    ]);

    Sanctum::actingAs($this->teacher);

    $this->getJson("/api/v1/billing/packages?course={$this->course->uuid}")
        ->assertForbidden();
});

it('offers nothing on a course with no approved rate', function (): void {
    $unpriced = courseWithRate((int) $this->workspace->getKey(), null);

    Sanctum::actingAs($this->student);

    expect($this->getJson("/api/v1/billing/packages?course={$unpriced->uuid}")->assertOk()->json())
        ->toBe([]);
});

// The purchase --------------------------------------------------------------

it('creates a pending order and a snapshot, and not one credit', function (): void {
    Sanctum::actingAs($this->student);

    $response = $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertCreated();

    $order = Order::query()->withoutWorkspaceScope()->firstOrFail();

    expect($order->kind)->toBe(OrderKind::Credits)
        ->and($order->status)->toBe('pending')
        // An int since 007, not the string `decimal:2` used to return.
        ->and($order->amount_minor)->toBe(22_000)
        ->and($response->json('order'))->toBe($order->uuid);

    $purchase = CreditPurchase::query()->withoutWorkspaceScope()->firstOrFail();

    // FR-021ح — all four, and they add up. Spec 015's books are generated from
    // these columns; a set of numbers that does not balance is a set of books
    // that does not either.
    expect($purchase->teacher_rate_minor)->toBe(20_000)
        ->and($purchase->operating_fee_minor)->toBe(2_000)
        ->and($purchase->gateway_fee_minor)->toBe(0)
        ->and($purchase->total_minor)->toBe(22_000)
        ->and($purchase->teacher_rate_minor + $purchase->operating_fee_minor + $purchase->gateway_fee_minor)
        ->toBe($purchase->total_minor);

    // FR-018 — nothing before approval.
    expect(billingBalance($this->workspace, $this->student, $this->course)->remaining_credits)->toBe(0);
});

it('keeps the snapshot when the teacher rate is approved anew', function (): void {
    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertCreated();

    // A new rate, approved after the purchase (FR-020 · FR-021ز · SC-015ج).
    app(WorkspaceContext::class)->forWorkspace($this->workspace, function (): void {
        SettlementRate::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'teacher_profile_id' => $this->course->teacher_profile_id,
            'amount_minor' => 9_000,
            'effective_from' => CarbonImmutable::now()->subMinute(),
        ]);
    });

    expect(CreditPurchase::query()->withoutWorkspaceScope()->firstOrFail()->total_minor)->toBe(22_000);

    // And the NEXT purchase is priced at the new rate — forward only.
    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertCreated()
        ->assertJsonPath('total_minor', (9_000 + 500) * 4);
});

it('refuses a purchase from a stranger', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertForbidden();

    expect(CreditPurchase::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a purchase from the teacher whose course it is', function (): void {
    // The second door on the same leak: the 201 body carries `total_minor`, so
    // a refusal on the listing alone would leave the number one POST away.
    Sanctum::actingAs($this->teacher);

    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertForbidden();

    expect(CreditPurchase::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a retired package even from a stale screen', function (): void {
    $this->package->forceFill(['is_active' => false])->save();

    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertStatus(422);
});

/*
| FR-021ي · Q-11 — the unredeemed ceiling.
|
| The platform holds this money until the sessions are delivered, and taking it
| back from a teacher who stopped delivering is a human negotiation, not a
| transaction. So the worst case is bounded by a number: this many undelivered
| sessions on one course, and no more.
*/
it('refuses a purchase that would exceed the unredeemed ceiling', function (): void {
    PlatformSettings::set('billing.max_unredeemed_credits', 6);

    $balance = billingBalance($this->workspace, $this->student, $this->course);
    grantCredits($balance, 4, 'held');

    Sanctum::actingAs($this->student);

    // 4 held + 4 more is 8, over a ceiling of 6.
    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertStatus(422);

    // Consumed credits are sessions already delivered and are nobody's exposure,
    // so the ceiling is measured on what REMAINS, not on lifetime purchases.
    app(CreditLedger::class)->post(new CreditMovement(
        balance: $balance,
        type: CreditTransactionType::Consume,
        credits: -3,
        sourceType: 'test_consume',
        sourceId: 1,
    ));

    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package->refresh()))
        ->assertCreated();
});

/*
| The hole the first version of the ceiling left, and the reason it is not a
| check on `remaining_credits` alone.
|
| Nothing mints until approval (FR-018), so a balance stays at zero while an
| order waits for its receipt. Two purchases posted back to back therefore both
| measure against an untouched balance, both pass, and both mint when approved
| days apart — leaving twice the ceiling held. The guard bounded intent instead
| of accumulation, which is precisely what Q-11 says not to do.
*/
it('counts purchases still awaiting approval against the ceiling', function (): void {
    PlatformSettings::set('billing.max_unredeemed_credits', 6);

    Sanctum::actingAs($this->student);

    // 4 credits, pending. Nothing minted, balance still zero.
    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertCreated();

    expect(billingBalance($this->workspace, $this->student, $this->course)->refresh()->remaining_credits)
        ->toBe(0);

    // 4 more would be 8 held under a ceiling of 6 — refused on the promise, not
    // on the balance.
    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertStatus(422);

    expect(CreditPurchase::query()->withoutWorkspaceScope()->count())->toBe(1);
});

/*
| ⛔ AND THE HOLE THE CASE ABOVE LEFT: PAYING EMPTIED THE CEILING.
|
| `pendingCreditsOn()` counted `status = 'pending'` literally, and
| `UploadPaymentReceipt` moves the order to `under_review`. So the loop was two
| ordinary steps and no exploit: buy up to the cap, upload the receipt, watch the
| cap read free, buy it again — with `ApproveOrder` minting from every one of
| them. `Order::isPending()` held the correct pair one file away the whole time
| and could not be called from inside a subquery; `scopeAwaitingDecision()` is the
| one spelling both of them read now.
*/
it('keeps counting a purchase after its receipt is uploaded', function (): void {
    PlatformSettings::set('billing.max_unredeemed_credits', 6);

    Sanctum::actingAs($this->student);

    $uuid = $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertCreated()
        ->json('order');

    $this->postJson("/api/v1/orders/{$uuid}/receipt", [
        'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        'method' => 'bank_transfer',
    ])->assertOk();

    expect(Order::query()->withoutWorkspaceScope()->where('uuid', $uuid)->value('status'))
        ->toBe('under_review');

    // Before the fix this answered 201: the order had left `pending`, the sum read
    // zero, and the student could walk the loop as many times as they liked.
    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertStatus(422);

    expect(CreditPurchase::query()->withoutWorkspaceScope()->count())->toBe(1);
});

/*
| ⛔ AND THE SUBQUERY CARRIED THE WORKSPACE SCOPE, SO THE CEILING COUNTED ZERO.
|
| `withoutWorkspaceScope()` on the outer `CreditPurchase` query reaches that model
| and no other — `whereHas('order', …)` builds a fresh `Order` query carrying
| `Order`'s own `BelongsToWorkspace`. Inert for a student in production, who is a
| member of no workspace and resolves a null context; NOT inert for a platform
| officer on the grant screen, nor for a guardian who owns one, because
| `WorkspaceContext::id()` falls back to `users.last_workspace_id` for everybody.
|
| ⚠️ THE FIXTURE IS THE TEST. A SECOND workspace, and the buyer resolving to it —
| a one-workspace fixture, or a buyer with a null context, passes against a build
| with no bypass in it at all.
*/
it('enforces the ceiling when the buyer resolves to another workspace', function (): void {
    PlatformSettings::set('billing.max_unredeemed_credits', 6);

    [$elsewhere] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);

    /*
    | ⚠️ `setCurrentWorkspace()`, NEVER `forget()`. `WorkspaceContext::forget()`
    | means «operate globally»: it sets `resolvedId = null` AND `resolved = true`,
    | so it PINS the answer to null rather than clearing the memo. Written with
    | `forget()` this case passed against a build with no bypass in it at all —
    | the scope was inert because the context was nailed to null, which is the
    | one state that cannot show the defect.
    */
    $this->setCurrentWorkspace($elsewhere, $this->student);

    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertCreated();

    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertStatus(422);

    expect(CreditPurchase::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('stops counting a purchase whose payment was rejected', function (): void {
    PlatformSettings::set('billing.max_unredeemed_credits', 6);

    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertCreated();

    // A rejected order will never mint, so holding it against the ceiling would
    // refuse a purchase over credits that do not and will not exist.
    Order::query()->withoutWorkspaceScope()->firstOrFail()
        ->forceFill(['status' => 'rejected'])->save();

    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertCreated();
});

/*
| FR-021ط · Q-11 — a course that stopped delivering stops selling.
|
| The one guard that works with nobody watching. Everything else in Q-11 needs a
| person: approving a payout, typing a deduction, deciding a refund.
*/
it('stops selling a course whose delivery went quiet', function (): void {
    PlatformSettings::set('billing.stop_selling_after_days', 30);

    $this->course->forceFill(['last_delivered_at' => CarbonImmutable::now()->subDays(31)])->save();

    Sanctum::actingAs($this->student);

    expect($this->getJson("/api/v1/billing/packages?course={$this->course->uuid}")->assertOk()->json())
        ->toBe([]);

    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertStatus(422);

    // A delivery today reopens it, with nothing else to reset.
    $this->course->forceFill(['last_delivered_at' => CarbonImmutable::now()])->save();

    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertCreated();
});

it('sells on a brand new course that has delivered nothing yet', function (): void {
    // Null is NEW, not stalled. Reading it as stalled would refuse every course
    // on the day it is published — the guard would fire on exactly the courses
    // it was never meant to touch.
    PlatformSettings::set('billing.stop_selling_after_days', 30);

    expect($this->course->last_delivered_at)->toBeNull();

    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course, $this->package))
        ->assertCreated();
});

it('stops selling a course that never delivered anything for too long', function (): void {
    PlatformSettings::set('billing.stop_selling_after_days', 30);

    $this->course->forceFill(['created_at' => CarbonImmutable::now()->subDays(45)])->save();

    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/billing/purchases', purchasePayload($this->course->refresh(), $this->package))
        ->assertStatus(422);
});
