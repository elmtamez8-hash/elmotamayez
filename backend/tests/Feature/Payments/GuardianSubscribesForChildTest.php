<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/*
|------------------------------------------------------------------------------
| A GUARDIAN BUYS FOR THEIR CHILD — reported by the user on 2026-09-08.
|------------------------------------------------------------------------------
|
| ⚠️ «حالياً وليّ الأمر قدر يشترك». `PurchaseSubscription` wrote
| `user_id = the caller` with no question asked, so a guardian who pressed
| «اشترك» BECAME the student: the subscription, the enrolment and the group
| membership were all written in their name, and the child they paid for held
| nothing. Nothing failed anywhere and the order rendered perfectly on `/orders`.
|
| ⚠️ **THE ORDER ALONE PROVES NOTHING, AND THAT IS THE WHOLE DESIGN OF THIS
| FILE.** `orders.user_id` is read as «the student» by `ActivateSubscription`,
| `CreateEnrollmentFromOrder` and `ApproveOrder` — so a test that stops at the
| 201 passes against a build where the money lands on the child and the ACCESS
| still lands on the guardian. Every happy-path case here approves the order and
| asks where the enrolment, the subscription and the seat actually went.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->otherWorkspace] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published', 'title' => 'الفيزياء ٣'])->save();

    $this->plan = Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'duration_days' => 30,
        'price_minor' => 45_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    $this->groupCohort = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Cohort => Cohort::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->course->getKey(),
            'name' => 'مجموعة السبت',
            'created_by' => $this->teacher->getKey(),
        ]),
    );

    /*
    | ⚠️ `last_workspace_id` LEFT NULL ON BOTH. Nothing on a student's path
    | writes that column in production, and it is what `WorkspaceContext::id()`
    | falls back to — a fixture that stamps it is testing a person who does not
    | exist, and every scope assertion below would be measuring them.
    */
    $this->child = User::factory()->create(['last_workspace_id' => null, 'first_name' => 'كريم']);
    $this->otherChild = User::factory()->create(['last_workspace_id' => null, 'first_name' => 'بدر']);
    $this->stranger = User::factory()->create(['last_workspace_id' => null]);

    $this->guardian = User::factory()->create([
        'last_workspace_id' => null,
        'platform_role' => PlatformRole::Parent,
    ]);

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $this->officer->forceFill(['last_workspace_id' => $this->otherWorkspace->getKey()])->save();

    app()->forgetInstance(WorkspaceContext::class);
});

function guardianOver(User $guardian, User $student, GuardianPermission ...$permissions): void
{
    ParentStudentRelation::query()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $student->getKey(),
        'student_name' => $student->first_name,
        'relation_type' => RelationType::Parent->value,
        'permissions' => array_map(
            fn (GuardianPermission $permission): string => $permission->value,
            $permissions,
        ),
        'status' => RelationStatus::Active->value,
    ]);
}

function subscribeAsGuardian(User $caller, array $body = []): TestResponse
{
    return test()->actingAs($caller, 'sanctum')->postJson('/api/v1/billing/subscriptions', [
        'plan_uuid' => (string) test()->plan->uuid,
        'mode' => 'cohort',
        'cohort_uuid' => (string) test()->groupCohort->uuid,
        ...$body,
    ]);
}

it('puts the subscription, the enrolment and the seat on the CHILD, never on the payer', function (): void {
    guardianOver($this->guardian, $this->child, GuardianPermission::Payments);

    $response = subscribeAsGuardian($this->guardian, ['student_uuid' => (string) $this->child->uuid])
        ->assertCreated();

    $order = Order::query()->withoutWorkspaceScope()
        ->where('uuid', $response->json('data.uuid'))->firstOrFail();

    // The two roles on one row, exactly as 024's `granted_by` migration defines
    // them: the student owns the order, the guardian is who created it.
    expect((int) $order->user_id)->toBe($this->child->getKey())
        ->and((int) $order->granted_by)->toBe($this->guardian->getKey());

    app(ApproveOrder::class)->handle($order, $this->officer);

    /*
    | ⚠️ THE THREE THAT MATTER. Each one was written in the guardian's name
    | before this change, and each is read from `orders.user_id` by a different
    | listener — so all three move together or none of them does.
    */
    expect(Subscription::query()->withoutWorkspaceScope()
        ->where('order_id', $order->getKey())
        ->where('student_user_id', $this->child->getKey())
        ->exists())->toBeTrue()
        ->and(Enrollment::query()->withoutWorkspaceScope()
            ->where('student_user_id', $this->child->getKey())
            ->where('course_id', $this->course->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->exists())->toBeTrue()
        ->and(CohortMembership::query()->withoutWorkspaceScope()
            ->where('cohort_id', $this->groupCohort->getKey())
            ->where('student_user_id', $this->child->getKey())
            ->whereNull('closed_at')
            ->exists())->toBeTrue();

    // And the negation, which is the actual bug: nothing at all in the payer's name.
    expect(Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->guardian->getKey())->exists())->toBeFalse()
        ->and(CohortMembership::query()->withoutWorkspaceScope()
            ->where('student_user_id', $this->guardian->getKey())->exists())->toBeFalse()
        ->and(Subscription::query()->withoutWorkspaceScope()
            ->where('student_user_id', $this->guardian->getKey())->exists())->toBeFalse();
});

it('refuses a guardian who names nobody, rather than defaulting to themselves', function (): void {
    // The default WAS the defect. A guardian account is not a learner account:
    // `dashboardAudience` reads `parent` as a guardian and hides the student
    // screens, so a subscription in their name is one they cannot even open.
    guardianOver($this->guardian, $this->child, GuardianPermission::Payments);

    subscribeAsGuardian($this->guardian)
        ->assertStatus(422)
        ->assertJsonPath('message', 'اختر الطالب الذي تشترك له.');

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a guardian without the payments permission on that child', function (): void {
    guardianOver($this->guardian, $this->child, GuardianPermission::Attendance);

    subscribeAsGuardian($this->guardian, ['student_uuid' => (string) $this->child->uuid])
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكنك الاشتراك لهذا الطالب.');

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('answers a stranger, a made-up uuid and an unauthorised child identically', function (): void {
    /*
    | ⚠️ ONE SENTENCE FOR THREE FAILURES, AND THE SAMENESS IS THE REQUIREMENT.
    | A distinct refusal for «no such account» turns the field into an identity
    | probe: pass uuids until the message changes and you have learnt which ones
    | belong to real children. Same rule that made `CreateFreezePeriod` ask the
    | directory before writing.
    */
    guardianOver($this->guardian, $this->child, GuardianPermission::Payments);

    $refusals = [
        (string) $this->stranger->uuid,
        '11111111-2222-3333-4444-555555555555',
    ];

    foreach ($refusals as $uuid) {
        subscribeAsGuardian($this->guardian, ['student_uuid' => $uuid])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكنك الاشتراك لهذا الطالب.');
    }

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('lets one guardian buy for two children at the same teacher', function (): void {
    /*
    | ⚠️ FR-011's «one pending order per buyer per teacher» is keyed on the
    | STUDENT now. Keyed on the payer it would refuse the second child outright —
    | a guardian with two children at one teacher could subscribe for one of them
    | and never for the other, with «لديك طلب قيد المراجعة على هذا الكورس».
    */
    guardianOver($this->guardian, $this->child, GuardianPermission::Payments);
    guardianOver($this->guardian, $this->otherChild, GuardianPermission::Payments);

    subscribeAsGuardian($this->guardian, ['student_uuid' => (string) $this->child->uuid])->assertCreated();
    subscribeAsGuardian($this->guardian, ['student_uuid' => (string) $this->otherChild->uuid])->assertCreated();

    expect(Order::query()->withoutWorkspaceScope()
        ->where('granted_by', $this->guardian->getKey())->count())->toBe(2);
});

it('shows the payer the order they placed, and says which child it is for', function (): void {
    /*
    | ⚠️ THE ORDER IS IN THE CHILD'S NAME, SO A FILTER ON `user_id` ALONE HIDES
    | IT FROM THE PERSON WHO OWES THE MONEY. No screen to upload the receipt on,
    | no decision to read — and `is_mine` is what gates both controls, so it
    | answers «mine to settle», not «I am the student».
    */
    guardianOver($this->guardian, $this->child, GuardianPermission::Payments);

    subscribeAsGuardian($this->guardian, ['student_uuid' => (string) $this->child->uuid])->assertCreated();

    $this->actingAs($this->guardian, 'sanctum')->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_mine', true)
        ->assertJsonPath('data.0.for_student_name', $this->child->name);
});

it('lets the payer upload the receipt for the order they granted', function (): void {
    // Without this the guardian creates an order they can never settle: it is
    // written, then the upload is refused, and it sits `pending` for ever.
    guardianOver($this->guardian, $this->child, GuardianPermission::Payments);

    $response = subscribeAsGuardian($this->guardian, ['student_uuid' => (string) $this->child->uuid])
        ->assertCreated();

    $this->actingAs($this->guardian, 'sanctum')->postJson(
        '/api/v1/orders/'.$response->json('data.uuid').'/receipt',
        [
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
            'method' => 'bank_transfer',
        ],
    )->assertOk();
});

it('leaves a student buying for themselves exactly as it was', function (): void {
    // No relation, no `student_uuid`, no `granted_by` — the path every existing
    // subscription test walks, unchanged.
    $response = subscribeAsGuardian($this->child)->assertCreated();

    $order = Order::query()->withoutWorkspaceScope()
        ->where('uuid', $response->json('data.uuid'))->firstOrFail();

    expect((int) $order->user_id)->toBe($this->child->getKey())
        ->and($order->granted_by)->toBeNull();
});

it('names the student when refusing a payer whose child already has a pending order', function (): void {
    /*
    | ⚠️ FR-011 IS KEYED ON THE STUDENT, WHICH IS RIGHT — AND MAKES «لديك» WRONG.
    | The child placed their own order; the guardian, standing on the screen,
    | would read «لديك طلب قيد المراجعة» and go looking through their own orders
    | for something that is not there. The rule does not change, only who the
    | sentence is addressed to.
    */
    guardianOver($this->guardian, $this->child, GuardianPermission::Payments);

    subscribeAsGuardian($this->child)->assertCreated();

    subscribeAsGuardian($this->guardian, ['student_uuid' => (string) $this->child->uuid])
        ->assertStatus(422)
        ->assertJsonPath('message', 'لهذا الطالب طلب قيد المراجعة على هذا الكورس.');

    // والعكسُ يبقى بصيغتِه: الطالبُ نفسُه يُخاطَبُ بـ«لديك».
    subscribeAsGuardian($this->child)
        ->assertStatus(422)
        ->assertJsonPath('message', 'لديك طلب قيد المراجعة على هذا الكورس.');
});

it('keeps the bank receipt with whoever uploaded it, not with the order owner', function (): void {
    /*
    | ⚠️ A NEW EXPOSURE CREATED BY THE FIX ITSELF, AND CLOSED WITH IT. The order
    | is in the child's name now, so it appears on THEIR `/orders` — and
    | `receipt_url` is a signed link to the image of their parent's bank
    | transfer. The order is their news; the receipt is their parent's document.
    */
    guardianOver($this->guardian, $this->child, GuardianPermission::Payments);

    $response = subscribeAsGuardian($this->guardian, ['student_uuid' => (string) $this->child->uuid])
        ->assertCreated();

    $uuid = $response->json('data.uuid');

    $this->actingAs($this->guardian, 'sanctum')->postJson("/api/v1/orders/{$uuid}/receipt", [
        'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        'method' => 'bank_transfer',
    ])->assertOk();

    // The payer keeps the link…
    $this->actingAs($this->guardian, 'sanctum')->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonPath('data.0.receipt_url', fn (?string $url) => $url !== null);

    // …and the child sees the order without it.
    $this->actingAs($this->child, 'sanctum')->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.receipt_url', null);
});
