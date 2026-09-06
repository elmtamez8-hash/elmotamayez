<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Filament\Pages\GrantCreditSubscription;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Spec 027 · US2 — the officer reads the whole request before deciding.
|
| ⚠️ TWO WORKSPACES, AND THE OFFICER OWNS ONE OF THEM. That single fixture line
| is what exposed all five layers of the 024 defect: `WorkspaceContext::id()`
| falls back to `users.last_workspace_id` for a platform officer exactly as it
| does for anybody else, so a queue left scoped — or an eager load left scoped
| over an unscoped root — shows one teacher's orders and calls it the platform.
| The fifth layer raises no status code at all: it renders a blank course.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspaceA, $this->teacherA] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);
    [$this->workspaceB, $this->teacherB] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية سلمى']);

    $this->courseB = courseWithRate((int) $this->workspaceB->getKey());
    $this->courseB->forceFill(['status' => 'published', 'title' => 'الفيزياء ٣'])->save();

    $this->planB = Plan::factory()->group()->create([
        'workspace_id' => $this->workspaceB->getKey(),
        'title' => 'الشهري — جماعي',
        'duration_days' => 30,
        'price_minor' => 45_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->courseB->uuid,
    ]);

    $this->cohortB = app(WorkspaceContext::class)->forWorkspace(
        $this->workspaceB,
        fn (): Cohort => Cohort::factory()->create([
            'workspace_id' => $this->workspaceB->getKey(),
            'course_id' => $this->courseB->getKey(),
            'name' => 'مجموعة السبت',
            'created_by' => $this->teacherB->getKey(),
        ]),
    );

    $this->student = User::factory()->create([
        'first_name' => 'سلمى',
        'last_name' => 'ع',
        'last_workspace_id' => null,
    ]);

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);

    /*
    | ⚠️ THE OFFICER OWNS WORKSPACE A WHILE EVERY ORDER BELOW IS IN B. Without
    | this line the fallback resolves to nothing and a scoped query would look
    | correct; with it, a scoped query returns zero rows and a scoped eager load
    | returns a null course.
    */
    $this->officer->forceFill(['last_workspace_id' => $this->workspaceA->getKey()])->save();
});

function pendingSubscriptionOrder(): Order
{
    $test = test();

    return app(PurchaseSubscription::class)->handle(
        $test->student,
        (string) $test->planB->uuid,
        'cohort',
        (string) $test->cohortB->uuid,
    );
}

function queueAs(User $user): Testable
{
    return Livewire::actingAs($user)->test(GrantCreditSubscription::class);
}

it('lists a pending subscription order raised in ANOTHER workspace', function (): void {
    $order = pendingSubscriptionOrder();

    queueAs($this->officer)->assertCanSeeTableRecords([$order]);
});

it('reads the four middle facts from the order’s own snapshot', function (): void {
    pendingSubscriptionOrder();

    queueAs($this->officer)
        ->assertSee('سلمى')
        ->assertSee($this->teacherB->name)
        // ⚠️ The course comes through a RELATION, and it is the one column the
        // 024 defect blanks with no error at all. Asserting it PRESENT is the
        // only thing that catches a dropped per-relation bypass — a query budget
        // reports that regression as an improvement.
        ->assertSee('الفيزياء ٣')
        ->assertSee('30 يوماً')
        ->assertSee('مجموعة السبت');
});

it('reads «حصص خاصّة» in words on a private request, never a dash', function (): void {
    $privatePlan = Plan::factory()->create([
        'workspace_id' => $this->workspaceB->getKey(),
        'price_minor' => 90_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->courseB->uuid,
    ]);

    app(PurchaseSubscription::class)
        ->handle($this->student, (string) $privatePlan->uuid, 'private');

    queueAs($this->officer)->assertSee('حصص خاصّة');
});

it('keeps the group name readable after the group is archived', function (): void {
    pendingSubscriptionOrder();

    $this->cohortB->forceFill(['status' => Cohort::ARCHIVED, 'archived_at' => now()])->save();

    // FR-013: the officer decides on a row that still says which group this was.
    queueAs($this->officer)->assertSee('مجموعة السبت');
});

it('refuses the page to somebody without the purchase-approval permission', function (): void {
    /*
    | ⚠️ ASKED OF `canAccess()` DIRECTLY, BECAUSE `Livewire::test()` MOUNTS THE
    | COMPONENT WITHOUT ROUTING AND SO NEVER CONSULTS IT. Measured while writing
    | this: a `Livewire::test()` as a teacher renders the page happily. The guard
    | is real — Filament calls `canAccess()` on the route — but a test that
    | mounts past it would report a wall that is not being asked about.
    |
    | FR-022 is «refused at the typed URL, not hidden from a menu», and this is
    | the question that answers it.
    */
    $this->actingAs($this->teacherB);
    expect(GrantCreditSubscription::canAccess())->toBeFalse();

    $this->actingAs($this->officer);
    expect(GrantCreditSubscription::canAccess())->toBeTrue();
});

it('approves from this screen and the order becomes approved exactly once', function (): void {
    $order = pendingSubscriptionOrder();

    queueAs($this->officer)->callTableAction('approve', $order->getKey());

    expect($order->refresh()->status)->toBe('approved')
        ->and($order->approved_by)->toBe($this->officer->getKey());
});

it('tells the officer who lost the race, in Arabic', function (): void {
    /*
    | ⚠️ THE SENTENCE WAS ENGLISH UNTIL SPEC 027, AND NOTHING CAUGHT IT because
    | no surface exercised the losing branch. Two officers on one queue press
    | «اعتمد» at the same instant as a matter of course.
    */
    $order = pendingSubscriptionOrder();

    app(ApproveOrder::class)->handle($order, $this->officer);

    expect(fn () => app(ApproveOrder::class)->handle($order->refresh(), $this->officer))
        ->toThrow(DomainException::class, 'اتُّخِذ القرار على هذا الطلب بالفعل.');
});

it('does not put an order the officer created on the queue they approve from', function (): void {
    /*
    | ⚠️ 024 · FR-008أ SURVIVES ONLY BECAUSE THE TWO SETS DO NOT INTERSECT, and
    | that is a filter rather than a rule anybody wrote — so it is asserted here.
    | The grant form below the queue writes `OrderKind::Credits`; the queue reads
    | `OrderKind::Subscription`. Add a kind to the queue's predicate and an
    | officer can approve their own signature on a blank page.
    */
    $manual = Order::query()->create([
        'workspace_id' => $this->workspaceB->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->courseB->getKey(),
        'kind' => OrderKind::Credits,
        'amount_minor' => 40_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
        'granted_by' => $this->officer->getKey(),
    ]);

    $student = pendingSubscriptionOrder();

    queueAs($this->officer)
        ->assertCanSeeTableRecords([$student])
        ->assertCanNotSeeTableRecords([$manual]);
});

it('costs the same number of queries whatever the number of pending orders', function (): void {
    pendingSubscriptionOrder();

    DB::enableQueryLog();
    queueAs($this->officer)->assertSuccessful();
    $withOne = count(DB::getQueryLog());
    DB::disableQueryLog();

    // ⚠️ Built with the log OFF — creating orders inside the measured window
    // counts INSERTs as reads and invents an N+1 that is not there.
    for ($i = 0; $i < 3; $i++) {
        $buyer = User::factory()->create(['last_workspace_id' => null]);

        app(PurchaseSubscription::class)
            ->handle($buyer, (string) $this->planB->uuid, 'cohort', (string) $this->cohortB->uuid);
    }

    // ⚠️ `flushQueryLog()`: disabling the log does not empty it, and the second
    // window would otherwise report the first window's queries as growth.
    DB::flushQueryLog();
    DB::enableQueryLog();
    queueAs($this->officer)->assertSuccessful();
    $withFour = count(DB::getQueryLog());
    DB::disableQueryLog();

    /*
    | ⚠️ NOT AN EQUALITY. A Livewire mount does one-time work on its first render
    | in a process (session, permission registrar, schema resolution), so the
    | second render is legitimately CHEAPER — measured 16 then 11. What an N+1
    | would do is make the count GROW with the rows, and that is what is asserted.
    */
    expect($withFour)->toBeLessThanOrEqual($withOne);
});
