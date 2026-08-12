<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Actions\EvaluateCreditLimit;
use App\Modules\Payments\Actions\RecordCreditPurchase;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\AccessWithheld;
use App\Modules\Payments\Exceptions\InsufficientCreditsException;
use App\Modules\Payments\Jobs\EvaluateCreditLimitsJob;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
| SC-012 · FR-014 · FR-036 … FR-040 · FR-048 — the ceiling moves by rule.
|
| Four claims, and they fail in four directions:
|
|   · the rise and the fall follow the PLATFORM'S numbers, so every one of them is
|     changed mid-test. A test on the defaults passes against an Action with the
|     defaults hardcoded, which is the implementation the requirement forbids;
|   · a prepaid workspace has no ceiling at all, whatever number is on the row;
|   · the teacher cannot move it — the exception is the platform's, and it leaves
|     a record naming who, when and why;
|   · and the demotion STICKS. A limit recomputed from scratch would hand the
|     defaulted student their ceiling back on their first payment afterwards.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // A deferring mode, or none of this exists: FR-014 pins the floor at zero in
    // PREPAID_CREDITS and the whole story is switched off at launch because of it.
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::ManualCollection->value]);

    $this->course = billingCourse($this->workspace);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // Refreshed: the row is created with only the attributes assigned, so the
    // schema defaults every counter is compared against are still unread.
    $this->balance = billingBalance($this->workspace, $this->student, $this->course)->refresh();

    // FR-048 — no agreement, no ceiling. Recorded here so the raise tests exercise
    // the raise; its absence is a test of its own.
    TermsConsent::factory()->create([
        'user_id' => $this->student->getKey(),
        'student_user_id' => $this->student->getKey(),
    ]);
});

/** One approved credit purchase, through the Action that records punctuality. */
function approvedPurchase(int $credits = 1): void
{
    $test = test();

    $order = Order::query()->create([
        'workspace_id' => $test->workspace->getKey(),
        'user_id' => $test->student->getKey(),
        'kind' => OrderKind::Credits,
        'amount_minor' => 10000,
        'currency' => 'QAR',
        'status' => 'approved',
        'approved_by' => $test->owner->getKey(),
    ]);

    CreditPurchase::query()->create([
        'credit_balance_id' => $test->balance->getKey(),
        'credit_package_id' => CreditPackage::factory()->create(['credits' => $credits])->getKey(),
        'course_id' => $test->balance->course_id,
        'workspace_id' => $test->workspace->getKey(),
        'order_id' => $order->getKey(),
        'credits' => $credits,
        'teacher_rate_minor' => 4000,
        'operating_fee_minor' => 500,
        'gateway_fee_minor' => 0,
        'total_minor' => 4500 * $credits,
        'currency' => 'QAR',
        'purchased_at' => now(),
    ]);

    app(RecordCreditPurchase::class)->handle($order);

    $test->balance->refresh();
}

/** Push the balance below zero and backdate how long it has been there. */
function overdueSince(int $days): void
{
    $test = test();

    app(CreditLedger::class)->post(new CreditMovement(
        balance: $test->balance,
        type: CreditTransactionType::Adjustment,
        credits: -2,
        sourceType: 'test_debt',
        sourceId: 1,
        enforceFloor: false,
    ));

    // The ledger stamped `negative_since` at now(); the sweep's question is about
    // days, and a test that waited for them would take a fortnight.
    CreditBalance::query()->withoutWorkspaceScope()
        ->whereKey($test->balance->getKey())
        ->update(['negative_since' => now()->subDays($days)]);

    $test->balance->refresh();
}

function limitOperator(): User
{
    $test = test();

    app(PermissionRegistrar::class)->setPermissionsTeamId($test->workspace->getKey());

    $operator = $test->addWorkspaceMember($test->workspace, Roles::TENANT_OWNER);
    $operator->givePermissionTo(Permissions::BILLING_LIMIT_MANAGE);
    $test->setCurrentWorkspace($test->workspace, $operator);

    return $operator;
}

// The rise -------------------------------------------------------------------

it('raises the ceiling every N on-time payments, N being the platform\'s number', function (): void {
    expect($this->balance->credit_limit_credits)->toBe(0);

    approvedPurchase();
    approvedPurchase();

    // Two of three. Nothing yet — a raise on the way to the threshold would be a
    // raise the rule never promised.
    expect($this->balance->credit_limit_credits)->toBe(0)
        ->and($this->balance->on_time_payments)->toBe(2);

    approvedPurchase();

    expect($this->balance->credit_limit_credits)->toBe(1)
        // Consumed, not merely compared: what earned the raise cannot earn it
        // again on the next evaluation, which is what makes the sweep and the
        // purchase path safe to both call the Action.
        ->and($this->balance->on_time_payments)->toBe(0);

    // ⚠️ THE DISCRIMINATING ASSERTION. Everything above passes against an Action
    // with 3 and 1 written into it; this is the line that fails if the numbers
    // are not read from platform_settings, which is FR-037's whole claim.
    PlatformSettings::set('billing.limit.increase_after_on_time', 1);
    PlatformSettings::set('billing.limit.increase_by_credits', 2);

    approvedPurchase();

    expect($this->balance->credit_limit_credits)->toBe(3);
});

it('never passes the platform\'s cap, however many payments land', function (): void {
    PlatformSettings::set('billing.limit.increase_after_on_time', 1);
    PlatformSettings::set('billing.limit.max_credits', 2);

    approvedPurchase();
    approvedPurchase();
    approvedPurchase();
    approvedPurchase();

    expect($this->balance->credit_limit_credits)->toBe(2);
});

it('refuses to raise a ceiling for a student with no recorded consent', function (): void {
    // FR-048 — the agreement is the gate. Removed here, so this is the same
    // student and the same three payments as the first test.
    TermsConsent::query()->delete();

    approvedPurchase();
    approvedPurchase();
    approvedPurchase();

    expect($this->balance->credit_limit_credits)->toBe(0);
});

// The fall -------------------------------------------------------------------

it('drops the ceiling to zero after the platform\'s number of days in the red', function (): void {
    $this->balance->forceFill(['credit_limit_credits' => 3])->save();

    overdueSince(15);

    // Fourteen is the default and fifteen is past it.
    app(EvaluateCreditLimitsJob::class)->handle(
        app(WorkspaceContext::class),
        app(EvaluateCreditLimit::class),
        app(BillingSettings::class),
    );

    expect($this->balance->refresh()->credit_limit_credits)->toBe(0);
});

it('reads the late window from settings rather than counting fourteen itself', function (): void {
    PlatformSettings::set('billing.limit.decrease_after_late_days', 30);

    $this->balance->forceFill(['credit_limit_credits' => 3])->save();

    overdueSince(15);

    app(EvaluateCreditLimit::class)->handle($this->balance);

    // Fifteen days is late under the default and not late under this one. A test
    // that only ever asserted the drop would pass against a hardcoded 14.
    expect($this->balance->refresh()->credit_limit_credits)->toBe(3);
});

it('tells the student their access stopped, on a day no credit moved', function (): void {
    // FR-033 · T136 · T117's second dispatch site. The ceiling is what changed,
    // not the balance — so the movement path cannot see this flip at all, and
    // without a dispatch here the student finds out by being refused a booking.
    //
    // Event::fake, never Queue::fake: a queued listener is pushed as Laravel's
    // CallQueuedListener wrapper, so faking the listener class fakes nothing.
    Event::fake([AccessWithheld::class]);

    // −2 against a ceiling of 3 is inside the room they were given: not blocked.
    $this->balance->forceFill(['credit_limit_credits' => 3])->save();

    overdueSince(15);

    app(EvaluateCreditLimit::class)->handle($this->balance->refresh());

    Event::assertDispatched(AccessWithheld::class);
});

it('announces the same flip when the platform lowers the ceiling by hand', function (): void {
    Event::fake([AccessWithheld::class]);

    $this->balance->forceFill(['credit_limit_credits' => 3])->save();

    overdueSince(1);

    Sanctum::actingAs(limitOperator());

    $this->patchJson("/api/v1/manage/billing/students/{$this->student->uuid}/limit", [
        'course' => $this->course->uuid,
        'credit_limit_credits' => 0,
        'reason' => 'سحب التسهيل بعد اتفاق منتهٍ',
    ])->assertOk();

    Event::assertDispatched(AccessWithheld::class);
});

it('keeps the demotion after the student pays again', function (): void {
    $this->balance->forceFill(['credit_limit_credits' => 3, 'on_time_payments' => 2])->save();

    overdueSince(20);

    app(EvaluateCreditLimit::class)->handle($this->balance->refresh());

    expect($this->balance->refresh()->credit_limit_credits)->toBe(0)
        // The counter falls with the ceiling: two thirds of the way to a raise is
        // not where a defaulting student resumes.
        ->and($this->balance->on_time_payments)->toBe(0);

    // ⚠️ The claim that a recompute would break. `limit = f(consent, history)`
    // evaluated fresh hands this student their 1 back here, and Q-9's "ينخفض إلى
    // صفر" would have lasted exactly one payment.
    approvedPurchase(3);

    expect($this->balance->refresh()->credit_limit_credits)->toBe(0);
});

// FR-014 — prepaid has no ceiling at all --------------------------------------

it('refuses to go below zero in a prepaid workspace whatever the limit says', function (): void {
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::PrepaidCredits->value]);

    // A number left on the row by a workspace that used to defer. The floor must
    // ignore it entirely, not subtract from it.
    $this->balance->forceFill(['credit_limit_credits' => 3])->save();

    $ledger = app(CreditLedger::class);

    // The mode branch, which is what FR-014 actually is: the floor answers zero
    // with a ceiling of three sitting on the row.
    expect($ledger->floorForBalance($this->balance))->toBe(0);

    expect(fn () => $ledger->post(new CreditMovement(
        balance: $this->balance,
        type: CreditTransactionType::Consume,
        credits: -1,
        sourceType: 'test_consume',
        sourceId: 99,
        enforceFloor: true,
    )))->toThrow(InsufficientCreditsException::class);

    expect($this->balance->refresh()->remaining_credits)->toBe(0);

    // And an evaluation clears the stale number, so the panel stops showing a
    // ceiling that means nothing.
    app(EvaluateCreditLimit::class)->handle($this->balance);

    expect($this->balance->refresh()->credit_limit_credits)->toBe(0);
});

// FR-038 · FR-039 — the exception, and its record ------------------------------

it('answers 403 to the teacher who tries to move a ceiling', function (): void {
    Sanctum::actingAs($this->owner);

    $this->patchJson("/api/v1/manage/billing/students/{$this->student->uuid}/limit", [
        'course' => $this->course->uuid,
        'credit_limit_credits' => 3,
        'reason' => 'طالب ملتزم',
    ])->assertForbidden();

    expect($this->balance->refresh()->credit_limit_credits)->toBe(0);
});

it('lets the platform move it, and records who, when and why', function (): void {
    Sanctum::actingAs(limitOperator());

    $this->patchJson("/api/v1/manage/billing/students/{$this->student->uuid}/limit", [
        'course' => $this->course->uuid,
        'credit_limit_credits' => 2,
        'reason' => 'اتفاق مع وليّ الأمر على السداد نهاية الشهر',
    ])->assertOk()->assertJsonPath('data.credit_limit_credits', 2);

    expect($this->balance->refresh()->credit_limit_credits)->toBe(2);

    // FR-039 — activity_log, never a table of its own (R15). The old value, the
    // new one and the reason, on a row that names its causer.
    $entry = Activity::query()->where('description', 'credit_limit.changed')->latest('id')->firstOrFail();

    expect($entry->properties['from'])->toBe(0)
        ->and($entry->properties['to'])->toBe(2)
        ->and($entry->properties['reason'])->toBe('اتفاق مع وليّ الأمر على السداد نهاية الشهر')
        ->and($entry->causer_id)->not->toBeNull();
});

it('refuses a ceiling above the platform cap and one with no consent behind it', function (): void {
    Sanctum::actingAs(limitOperator());

    $this->patchJson("/api/v1/manage/billing/students/{$this->student->uuid}/limit", [
        'course' => $this->course->uuid,
        'credit_limit_credits' => 99,
        'reason' => 'تجاوز',
    ])->assertStatus(422);

    TermsConsent::query()->delete();

    $this->patchJson("/api/v1/manage/billing/students/{$this->student->uuid}/limit", [
        'course' => $this->course->uuid,
        'credit_limit_credits' => 2,
        'reason' => 'بلا موافقة',
    ])->assertStatus(422);

    expect($this->balance->refresh()->credit_limit_credits)->toBe(0);
});

it('answers 403 for a student who is not enrolled here, never 404', function (): void {
    $stranger = User::factory()->create();

    Sanctum::actingAs(limitOperator());

    // 404 for a stranger and 403 for one of ours is an oracle: it answers "does
    // this person exist" for anyone who can spell a uuid (NFR-001أ).
    $this->patchJson("/api/v1/manage/billing/students/{$stranger->uuid}/limit", [
        'course' => $this->course->uuid,
        'credit_limit_credits' => 1,
        'reason' => 'تجربة',
    ])->assertForbidden();

    $this->patchJson('/api/v1/manage/billing/students/'.fake()->uuid().'/limit', [
        'course' => $this->course->uuid,
        'credit_limit_credits' => 1,
        'reason' => 'تجربة',
    ])->assertForbidden();
});
