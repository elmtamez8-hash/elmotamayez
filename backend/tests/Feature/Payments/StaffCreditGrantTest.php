<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\ListCreditPackages;
use App\Modules\Payments\Actions\PurchaseCredits;
use App\Modules\Payments\Actions\RecordCreditPurchase;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Sanctum\Sanctum;

/*
| Spec 024 — منحُ اشتراكِ حصصٍ بيدِ موظّفِ المنصّة.
|
| ⚠️ TWO WORKSPACES, AND THAT IS NOT DECORATION. Every write behind a platform
| permission in this product has to be measured across two of them: a fixture
| with one makes "the officer sees the right rows" true by having no wrong rows
| to see, and it is how `ExecuteTeacherOffboarding` shipped unable to complete an
| exit for an officer whose own workspace differed.
|
| ⚠️ AND THE STUDENT IS BUILT WITH NOTHING. `User::factory()` alone — no seeder,
| no `addWorkspaceMember`, no `last_workspace_id`. A real student is a member of
| no workspace at all, so a fixture that grants them one hands them a way in that
| production never gives them, and the whole point of FR-004 is that they have
| none.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspaceA, $this->teacherA] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);
    [$this->workspaceB, $this->teacherB] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية سلمى']);

    $this->courseA = courseWithRate((int) $this->workspaceA->getKey(), 5000);
    $this->courseB = courseWithRate((int) $this->workspaceB->getKey(), 5000);

    PlatformSettings::set('billing.operating_fee_minor.individual', 500);
    PlatformSettings::set('billing.gateway_fee_bps', 0);
    PlatformSettings::set('billing.gateway_fixed_fee_minor', 0);

    $this->package = CreditPackage::query()->create([
        'name' => 'ثماني حصص',
        'credits' => 8,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    // The person the whole feature exists for: an account and nothing else.
    $this->student = User::factory()->create(['first_name' => 'سارة', 'last_name' => 'ع']);

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
});

/** The grant, as the officer's screen performs it. */
function grant(?User $student = null, ?object $course = null): CreditPurchase
{
    $test = test();

    return app(PurchaseCredits::class)->handle(
        $student ?? $test->student,
        $course ?? $test->courseA,
        $test->package,
        null,
        $test->officer,
    );
}

function creditsOn(User $student, object $course): int
{
    return (int) (CreditBalance::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $student->getKey())
        ->where('course_id', $course->getKey())
        ->value('remaining_credits') ?? 0);
}

// ── T012 · FR-003 · SC-003 · SC-009 ────────────────────────────────────────

it('grants to a student with no enrolment and no membership, and mints nothing yet', function (): void {
    $purchase = grant();

    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    expect($order->status)->toBe('pending')
        ->and($purchase->credits)->toBe(8)
        /*
        | The snapshot is the sale, frozen — spec 015's books read it. ⚠️ AND ALL
        | FOUR AMOUNTS ARE FOR THE WHOLE PURCHASE, not per credit: 5000 × 8 for
        | the teacher and 500 × 8 for the platform. `teacher_rate_minor` reads
        | like a per-unit rate and is not one, so the identity is asserted rather
        | than the convention remembered — the first three ADD UP to the fourth.
        */
        ->and($purchase->teacher_rate_minor)->toBe(40_000)
        ->and($purchase->operating_fee_minor)->toBe(4_000)
        ->and($purchase->gateway_fee_minor)->toBe(0)
        ->and($purchase->total_minor)->toBe(44_000)
        ->and($purchase->teacher_rate_minor + $purchase->operating_fee_minor + $purchase->gateway_fee_minor)
        ->toBe($purchase->total_minor);

    /*
    | ⚠️ READ THE ROW, NOT THE RETURN VALUE. FR-003 is a claim about the
    | database at this moment, and a `CreditBalance` that does not exist yet
    | reads as zero exactly as an untouched one does — which is the answer.
    */
    expect(creditsOn($this->student, $this->courseA))->toBe(0)
        ->and(CreditLot::query()->withoutWorkspaceScope()->count())->toBe(0);
});

// ── T013 · FR-009 ──────────────────────────────────────────────────────────

it('puts the credits on that teacher and no other when the grant is approved', function (): void {
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    $order->forceFill(['status' => 'approved', 'approved_by' => $this->officer->getKey()])->save();
    app(RecordCreditPurchase::class)->handle($order);

    expect(creditsOn($this->student, $this->courseA))->toBe(8)
        // The other teacher's course is the control: a balance is per course, and
        // nothing about a grant is portable between them.
        ->and(creditsOn($this->student, $this->courseB))->toBe(0)
        ->and(CreditLot::query()->withoutWorkspaceScope()->sum('credits_remaining'))->toBe(8);
});

// ── T014 · FR-005 ──────────────────────────────────────────────────────────

it('refuses a grant to somebody who teaches the course', function (): void {
    /*
    | ⚠️ THIS IS THE TEST THAT PROVES THE SKIP IS PARTIAL.
    |
    | FR-004 lets the officer past the ways in; skip the whole predicate and this
    | passes silently — and two totals on two package sizes then solve for the
    | platform's constants and invert every OTHER teacher's approved settlement
    | rate. Removing `isSeller()` from `PurchaseCredits` must turn this red.
    */
    expect(fn () => grant($this->teacherA))->toThrow(AuthorizationException::class);

    expect(Order::query()->withoutWorkspaceScope()->where('user_id', $this->teacherA->getKey())->count())->toBe(0);
});

it('refuses to price a grant for somebody who teaches the course', function (): void {
    // The same refusal on the preview read — otherwise the screen shows a price
    // for a grant the save will reject.
    expect(fn () => app(ListCreditPackages::class)->handle($this->teacherA, $this->courseA, $this->officer))
        ->toThrow(AuthorizationException::class);
});

it('prices the packages for a brand-new student so the officer sees the amount first', function (): void {
    // FR-006. Without the skip this is an empty list for the common case, and
    // the officer reads "nothing to sell" about a course that sells fine.
    $offers = app(ListCreditPackages::class)->handle($this->student, $this->courseA, $this->officer);

    expect($offers)->toHaveCount(1)
        ->and($offers[0]['price']->totalMinor)->toBe(44_000);

    // And the student's own read is unchanged: they are party to nothing.
    expect(fn () => app(ListCreditPackages::class)->handle($this->student, $this->courseA))
        ->toThrow(AuthorizationException::class);
});

// ── T015 · FR-004أ ─────────────────────────────────────────────────────────

it('creates no enrolment, so a grant buys sessions and not the course content', function (): void {
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);
    $order->forceFill(['status' => 'approved'])->save();
    app(RecordCreditPurchase::class)->handle($order);

    expect(Enrollment::query()->withoutWorkspaceScope()->where('student_user_id', $this->student->getKey())->count())
        ->toBe(0);
});

// ── T016 · FR-008ب · SC-010 ────────────────────────────────────────────────

it('records the creator and the approver as two separate facts', function (): void {
    $purchase = grant();

    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    expect($order->user_id)->toBe($this->student->getKey())
        ->and($order->granted_by)->toBe($this->officer->getKey())
        ->and($order->grantor->getKey())->toBe($this->officer->getKey());
});

it('leaves granted_by null when the student bought it themselves', function (): void {
    /*
    | ⚠️ THE OTHER DIRECTION, AND IT IS NOT SYMMETRY FOR ITS OWN SAKE. One
    | direction alone passes over an implementation that stamps the officer on
    | every order on the platform — including the ones nobody granted.
    */
    $buyer = $this->addWorkspaceMember($this->workspaceA, Roles::STUDENT);

    $purchase = app(PurchaseCredits::class)->handle($buyer, $this->courseA, $this->package);

    expect(Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id)->granted_by)->toBeNull();
});

// ── T017 ───────────────────────────────────────────────────────────────────

it('ignores granted_by arriving by mass assignment', function (): void {
    /*
    | It decides an audit fact, so it is written by the Action and by nothing
    | else — the `captured_order_id` rule. Mass-assignable it becomes a second
    | door to that fact from outside the transaction that owns it, and this test
    | is what fails if a later tidy-up adds it to `$fillable`.
    */
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    $order->update(['granted_by' => $this->teacherA->getKey(), 'rejection_reason' => 'مسموح']);

    expect($order->refresh()->granted_by)->toBe($this->officer->getKey())
        // The control: the same call DID write a fillable field, so this is a
        // guard passing rather than an update that silently did nothing at all.
        ->and($order->rejection_reason)->toBe('مسموح');
});

// ── T011 · R6 · SC-006 ─────────────────────────────────────────────────────

it('lets an officer who owns a workspace read and approve an order in another one', function (): void {
    /*
    | ⚠️ THE FIXTURE IS THE TEST HERE.
    |
    | `BasePolicy` is explicit that a RESOLVED context which does not match is
    | still a denial, and `WorkspaceContext::id()` falls back to
    | `users.last_workspace_id` for every user — a platform officer included. So
    | an officer who also owns a workspace was refused every order outside it.
    | It stayed invisible because every fixture builds that officer with no
    | workspace at all, where a null context raises no objection.
    */
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    // The officer is standing in workspace B; the order lives in A.
    $this->setCurrentWorkspace($this->workspaceB, $this->officer);

    Sanctum::actingAs($this->officer);

    $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk();
    $this->postJson("/api/v1/orders/{$order->uuid}/approve")->assertOk();

    expect($order->refresh()->status)->toBe('approved');
});

it('lets an officer with no workspace of their own do the same', function (): void {
    // The case every existing fixture already covered — kept so the pair reads
    // as "both, always" rather than as the one that happened to be written.
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    app(WorkspaceContext::class)->forget();
    Sanctum::actingAs($this->officer);

    $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk();
    $this->postJson("/api/v1/orders/{$order->uuid}/approve")->assertOk();

    expect($order->refresh()->status)->toBe('approved');
});

it('still refuses the teacher whose workspace the order sits in', function (): void {
    // The reorder must not have widened anything: a credit purchase is the
    // platform's sale, and the teacher is the payee downstream.
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    Sanctum::actingAs($this->teacherA);

    $this->getJson("/api/v1/orders/{$order->uuid}")->assertForbidden();
    $this->postJson("/api/v1/orders/{$order->uuid}/approve")->assertForbidden();

    expect($order->refresh()->status)->toBe('pending');
});

it('lets the officer attach the receipt that arrived outside the product', function (): void {
    // FR-007 — the student never opened the app, so the image is in the
    // officer's hands and nowhere else.
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    $this->setCurrentWorkspace($this->workspaceB, $this->officer);
    Sanctum::actingAs($this->officer);

    $this->post(
        "/api/v1/orders/{$order->uuid}/receipt",
        ['receipt' => Illuminate\Http\UploadedFile::fake()->image('receipt.jpg')],
    )->assertOk();

    expect($order->refresh()->hasMedia('receipt'))->toBeTrue();
});
