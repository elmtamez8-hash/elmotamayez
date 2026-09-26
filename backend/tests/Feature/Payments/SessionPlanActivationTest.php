<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\ScheduleClassSession;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Jobs\ClaimSubscriptionSeatsJob;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Listeners\ActivateSubscription;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

/*
| ٠٣٦ · US2 — a plan sold by the HOUR pours credits, and writes no subscription.
|
| ⛔ NO BARE `Queue::fake()`. `ActivateSubscription` is a QUEUED listener, so a
| fake with no arguments swallows it whole — and «zero rows in `subscriptions`»
| becomes a confident assertion about a listener that never ran, true of every
| build there has ever been. Only the timeline jobs are faked, for the reason
| they always are: a `->delay()` runs immediately on `sync`.
|
| ⚠️ AND EVERY ZERO HERE HAS A POSITIVE CONTROL BESIDE IT. «No subscription
| row», «no booking» and «credits did not move twice» are each equally true of a
| build that does nothing at all; the month-shaped plan bought and approved by
| the same helpers is what makes them mean something.
|
| ⚠️ THE OFFICER OWNS A DIFFERENT WORKSPACE — the fixture line that exposed all
| five layers of the 024 defect on this exact approval path.
*/
beforeEach(function (): void {
    /*
    | ⚠️ THE CLAIM JOB IS FAKED **AS WELL**, and the two seat cases assert on the
    | DISPATCH rather than on a booking row. What ٠٣٦ · T115 decides is whether
    | the automatic claim is TRIGGERED at all; measuring a row instead would make
    | the case depend on everything inside that job — a fixture missing one of
    | its eligibility conditions reads exactly like a decision that was taken.
    */
    Queue::fake([
        CloseClassSessionJob::class,
        SendSessionReportsJob::class,
        ClaimSubscriptionSeatsJob::class,
    ]);

    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->otherWorkspace] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published', 'title' => 'الفيزياء'])->save();

    // ⚠️ A SECOND PUBLISHED COURSE, measured at zero. «The balance rose» is true
    // of a build that credited the wrong course; naming the destination is what
    // makes it an assertion.
    $this->otherCourse = courseWithRate((int) $this->workspace->getKey());
    $this->otherCourse->forceFill(['status' => 'published', 'title' => 'الكيمياء'])->save();

    $this->cohort = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Cohort => Cohort::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->course->getKey(),
            'created_by' => $this->teacher->getKey(),
            'name' => 'مجموعة السبت',
        ]),
    );

    /*
    | ⛔ A FUTURE SESSION IN THE GROUP, AND WITHOUT IT THE «no seat» CASE IS
    | VACUOUS. Measured: with an empty timetable, deleting the guard that stops
    | the claim job left every case green — there was nothing to book either way.
    | One scheduled lesson is what makes the month buyer's seat appear and the
    | hours buyer's absence mean something.
    */
    // ⚠️ `forWorkspace`, NOT `setCurrentWorkspace`. `class_sessions.workspace_id`
    // is auto-filled from the context and this fixture has none — and freezing a
    // context here would also hand the officer below a workspace they must not
    // have, which is the fixture line the 024 defect turned on.
    app(WorkspaceContext::class)->forWorkspace($this->workspace, fn () => app(ScheduleClassSession::class)->handle(new ScheduleSessionData(
        teacherProfileId: (int) $this->course->teacher_profile_id,
        title: 'درس المجموعة',
        type: ClassSessionType::Group,
        startsAt: CarbonImmutable::parse('+5 days'),
        durationMinutes: 60,
        seatsTotal: 8,
        courseId: (int) $this->course->getKey(),
        cohortId: (int) $this->cohort->getKey(),
    ), $this->teacher));

    $this->student = User::factory()->create(['last_workspace_id' => null]);

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $this->officer->forceFill(['last_workspace_id' => $this->otherWorkspace->getKey()])->save();
});

/** A plan of this teacher in the shape the case needs — file-local name. */
function sessionPlanShaped(bool $bySessions): Plan
{
    $factory = $bySessions ? Plan::factory()->bySessions(12) : Plan::factory();

    return $factory->group()->create([
        'workspace_id' => test()->workspace->getKey(),
        'coverage_type' => PlanCoverage::Cohort,
        'coverage_uuid' => test()->cohort->uuid,
    ]);
}

function sessionPlanBought(Plan $plan): Order
{
    $order = app(PurchaseSubscription::class)->handle(
        test()->student,
        (string) $plan->uuid,
        'cohort',
        (string) test()->cohort->uuid,
    );

    app(ApproveOrder::class)->handle($order->refresh(), test()->officer);

    return $order->refresh();
}

/**
 * ⚠️ NAMED FOR THIS FILE. A Pest helper is a GLOBAL function, and
 * `StaffCreditGrantTest` already declares `creditsOn()` with a different
 * signature — invisible while each file gets its own process, and a fatal
 * «Cannot redeclare function» the moment one worker loads both, which is every
 * `pest --parallel` run and therefore every CI shard.
 */
function sessionPlanCredits(string $courseKey): int
{
    $balance = app(CreditAccounts::class)->existingBalanceFor(test()->student, test()->{$courseKey});

    return $balance === null ? 0 : (int) $balance->remaining_credits;
}

it('pours the hours into the ledger and writes no subscription', function (): void {
    sessionPlanBought(sessionPlanShaped(bySessions: true));

    expect(Subscription::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and(sessionPlanCredits('course'))->toBe(12);
});

it('credits the group course and nothing else in the workspace', function (): void {
    sessionPlanBought(sessionPlanShaped(bySessions: true));

    // ⛔ THE DESTINATION IS NAMED. ٠٣٥ keeps one balance per COURSE — «+10 in
    // maths and −6 in physics» is not +4 — so «a consumption row exists» is not
    // the same claim as «the right balance moved».
    expect(sessionPlanCredits('course'))->toBe(12)
        ->and(sessionPlanCredits('otherCourse'))->toBe(0);
});

it('still writes a subscription, a membership and a seat for a plan sold by the month', function (): void {
    /*
    | ⚠️ THE POSITIVE CONTROL, AND IT IS WHAT FALLS IF THE SHAPE BRANCH RUNS TOO
    | EARLY. The enrolment, the membership and the automatic seat are all built
    | today FROM the subscription row and its end date — branch above them and
    | the month buyer silently loses all three.
    */
    $order = sessionPlanBought(sessionPlanShaped(bySessions: false));

    $subscription = Subscription::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->first();

    expect($subscription)->not->toBeNull()
        ->and(Enrollment::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->count())->toBe(1)
        ->and(CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $this->student->getKey())
            ->whereNull('closed_at')
            ->count())->toBe(1);

    // ⚠️ AND THE SEAT CLAIM IS TRIGGERED. It is dispatched with the
    // subscription's end date — exactly the argument the hours shape has none of.
    Queue::assertPushed(ClaimSubscriptionSeatsJob::class);
});

it('tells the hours buyer and the paying guardian that the plan is active', function (): void {
    $payer = guardianOf($this->student, [GuardianPermission::Payments]);
    $scheduleOnly = guardianOf($this->student, [GuardianPermission::Schedule]);

    sessionPlanBought(sessionPlanShaped(bySessions: true));

    // `session_plan_activated`, not `subscription_activated`: the hours shape
    // writes no subscription row, so the month's wording would promise a window
    // that does not exist.
    assertNotifiedOnce($this->student, NotificationType::SessionPlanActivated);
    assertNotifiedOnce($payer, NotificationType::SessionPlanActivated);

    expect(wasNotified($scheduleOnly, NotificationType::SessionPlanActivated))->toBeFalse()
        ->and(wasNotified($this->student, NotificationType::SubscriptionActivated))->toBeFalse();
});

it('gives the hours buyer an enrolment and a membership too', function (): void {
    $order = sessionPlanBought(sessionPlanShaped(bySessions: true));

    // Everything the month buyer gets except the window and the automatic seat.
    expect(Enrollment::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->count())->toBe(1)
        ->and(CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $this->student->getKey())
            ->whereNull('closed_at')
            ->count())->toBe(1);
});

it('does not pour twice when the approval is redelivered', function (): void {
    /*
    | ⛔ THE BITE PROOF, WRITTEN DOWN: delete `sourceType` from the movement in
    | `ActivateSubscription::activateSessionPlan()` and re-run — this case MUST
    | turn red. Without it the case passes on an early return (the order is
    | already activated) rather than on the idempotency key, which is the thing
    | it claims to measure.
    */
    $order = sessionPlanBought(sessionPlanShaped(bySessions: true));

    app(ActivateSubscription::class)->handle(new PaymentApproved($order->refresh()));

    expect(sessionPlanCredits('course'))->toBe(12)
        // And one sale row, not two — the finance screen counts money, so a
        // duplicate there is a second payment that never happened.
        ->and(CreditPurchase::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('books no seat automatically for an hours plan', function (): void {
    /*
    | ⛔ AN ENGINEERING DECISION, MEASURED. The claim job takes a window-end that
    | is not nullable, and with no time limit it would book every future session
    | of the group — twelve hours buying forty chairs — each one skipping ٠٣٥'s
    | credit hold on a comment saying «a subscriber holds no credits», which the
    | buyer of hours does. Seats with no hold behind them, charged at attendance.
    */
    sessionPlanBought(sessionPlanShaped(bySessions: true));

    Queue::assertNotPushed(ClaimSubscriptionSeatsJob::class);

    // And nothing booked by any other road either.
    expect(SessionBooking::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('records the sale so the finance screen sees money, with no invented fees', function (): void {
    $order = sessionPlanBought(sessionPlanShaped(bySessions: true));

    $sale = CreditPurchase::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->first();

    expect($sale)->not->toBeNull()
        ->and((int) $sale->credits)->toBe(12)
        ->and((int) $sale->total_minor)->toBe((int) $order->amount_minor)
        ->and((int) $sale->plan_id)->toBeGreaterThan(0)
        /*
        | ⛔ NULL, NEVER ZERO. The three components are what `CostPlusPricing`
        | computes for a PACKAGE; a plan's price is a number a human typed. Zeros
        | are read as facts by the books and the money dashboard — a teacher who
        | earned nothing on a sale that really happened.
        */
        ->and($sale->teacher_rate_minor)->toBeNull()
        ->and($sale->operating_fee_minor)->toBeNull()
        ->and($sale->gateway_fee_minor)->toBeNull()
        ->and($sale->credit_package_id)->toBeNull();
});

it('gives the approved group its place back, once, when the hours buyer changed group before activation', function (): void {
    /*
    | `ApproveOrder` claimed a place in the named group before the money moved;
    | the membership is written by the queued activation. A student who joined
    | another group in between left that place taken by nobody — the subscription
    | arm gave it back, the hours arm did not (owner decision 2026-09-26).
    */
    Queue::fake([CallQueuedListener::class, ClaimSubscriptionSeatsJob::class]);

    $order = sessionPlanBought(sessionPlanShaped(bySessions: true));

    expect($this->cohort->refresh()->members_count)->toBe(1);

    $other = app(WorkspaceContext::class)->forWorkspace($this->workspace, fn (): Cohort => Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->getKey(),
        'name' => 'مجموعة الأحد',
    ]));

    CohortMembership::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $other->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'joined_at' => now(),
    ]);

    $activate = fn () => app(ActivateSubscription::class)->handle(new PaymentApproved($order->refresh()));

    $activate();

    expect($this->cohort->refresh()->members_count)->toBe(0);

    // A redelivery posts no hours and must not give the place back again.
    $this->cohort->forceFill(['members_count' => 1])->save();

    $activate();

    expect($this->cohort->refresh()->members_count)->toBe(1);
});
