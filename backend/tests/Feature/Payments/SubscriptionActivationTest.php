<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| Spec 027 · US3 — one press produces an enrolment, a group, seats and a message.
|
| ⚠️ THE OFFICER OWNS A DIFFERENT WORKSPACE FROM THE ONE BEING APPROVED. That one
| fixture line is what exposed all five layers of the 024 defect, and this file
| exercises a platform-permission WRITE, where the same fallback applies.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->otherWorkspace] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published', 'title' => 'الفيزياء ٣'])->save();

    $this->plan = Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'الشهري — جماعي',
        'duration_days' => 30,
        'price_minor' => 45_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    $this->cohort = cohortNamed('مجموعة السبت');

    $this->student = User::factory()->create(['last_workspace_id' => null]);

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $this->officer->forceFill(['last_workspace_id' => $this->otherWorkspace->getKey()])->save();
});

function cohortNamed(string $name, ?int $capacity = null): Cohort
{
    $test = test();

    return app(WorkspaceContext::class)->forWorkspace(
        $test->workspace,
        fn (): Cohort => Cohort::factory()->create([
            'workspace_id' => $test->workspace->getKey(),
            'course_id' => $test->course->getKey(),
            'name' => $name,
            'capacity' => $capacity,
            'created_by' => $test->teacher->getKey(),
        ]),
    );
}

function orderForCohort(?Cohort $cohort = null): Order
{
    $test = test();

    return app(PurchaseSubscription::class)->handle(
        $test->student,
        (string) $test->plan->uuid,
        'cohort',
        (string) ($cohort ?? $test->cohort)->uuid,
    );
}

function approveIt(Order $order): Order
{
    return app(ApproveOrder::class)->handle($order, test()->officer);
}

it('turns one approval into a subscription, an enrolment, a group and a message', function (): void {
    $order = orderForCohort();

    approveIt($order);

    $subscription = Subscription::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->first();

    expect($subscription)->not->toBeNull()
        ->and(Enrollment::query()->withoutWorkspaceScope()
            ->where('student_user_id', $this->student->getKey())
            ->where('course_id', $this->course->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->exists())->toBeTrue()
        ->and(CohortMembership::query()->withoutWorkspaceScope()
            ->where('cohort_id', $this->cohort->getKey())
            ->where('student_user_id', $this->student->getKey())
            ->whereNull('closed_at')
            ->exists())->toBeTrue()
        ->and(Notification::query()
            ->where('recipient_user_id', $this->student->getKey())
            ->where('type', NotificationType::SubscriptionActivated->value)
            ->exists())->toBeTrue();
});

it('says out loud that no lesson is scheduled yet, rather than dropping the line', function (): void {
    // FR-029أ — an omitted line reads as a fault: the student refreshes, finds
    // nothing, and asks whether their payment went through.
    approveIt(orderForCohort());

    $notification = Notification::query()
        ->where('recipient_user_id', $this->student->getKey())
        ->where('type', NotificationType::SubscriptionActivated->value)
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->body_ar)->toContain('لم تُجدول حصة قادمة بعد');
});

it('refuses the APPROVAL when the group filled up after the order, writing nothing', function (): void {
    /*
    | FR-026. Refusing later — in the queued activation — is literally the state
    | the requirement exists to prevent: an approved order, money taken, and a
    | student in no group, with nothing on the officer's screen.
    */
    $order = orderForCohort();

    $this->cohort->forceFill(['capacity' => 1, 'members_count' => 1])->save();

    expect(fn () => approveIt($order))->toThrow(DomainException::class);

    $order->refresh();

    expect($order->status)->toBe('pending')
        ->and($order->approved_at)->toBeNull()
        ->and(Subscription::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->exists())->toBeFalse()
        ->and(Enrollment::query()->withoutWorkspaceScope()
            ->where('student_user_id', $this->student->getKey())->exists())->toBeFalse();
});

it('lets a renewal into the student’s OWN full group through', function (): void {
    /*
    | ⚠️ THE CASE A BARE `isJoinable()` PRE-CHECK REFUSES, AND IT IS THE ORDINARY
    | ONE. A renewing student's group is full OF THEM AND THEIR CLASSMATES, so
    | asking joinability before membership leaves their paid, approved order
    | `pending` for ever under «هذه المجموعة لم تعد متاحة» — US4·4 inverted.
    */
    approveIt(orderForCohort());

    $this->cohort->forceFill(['capacity' => 1])->save();
    $this->cohort->refresh();

    $renewal = orderForCohort();

    approveIt($renewal);

    expect($renewal->refresh()->status)->toBe('approved')
        ->and(CohortMembership::query()->withoutWorkspaceScope()
            ->where('cohort_id', $this->cohort->getKey())
            ->where('student_user_id', $this->student->getKey())
            ->whereNull('closed_at')
            ->count())->toBe(1);
});

it('refuses the approval rather than moving a student who joined another group', function (): void {
    /*
    | Neither obvious answer is acceptable: `JoinCohort` would throw AFTER the
    | money committed, and the membership writer would move them out of the group
    | they are in with nobody deciding it — a transfer bought for the price of the
    | cheapest plan, filed in the log as a join.
    */
    $order = orderForCohort();

    $elsewhere = cohortNamed('مجموعة الأحد');

    CohortMembership::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $elsewhere->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'joined_at' => now(),
    ]);

    expect(fn () => approveIt($order))->toThrow(DomainException::class);

    expect($order->refresh()->status)->toBe('pending')
        ->and(CohortMembership::query()->withoutWorkspaceScope()
            ->where('student_user_id', $this->student->getKey())
            ->whereNull('closed_at')
            ->where('cohort_id', $elsewhere->getKey())
            ->exists())->toBeTrue();
});

it('opens the private-session door instead of a group when that is what was bought', function (): void {
    $plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'duration_days' => 30,
        'price_minor' => 60_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    $order = app(PurchaseSubscription::class)->handle($this->student, (string) $plan->uuid, 'private');

    approveIt($order);

    $notification = Notification::query()
        ->where('recipient_user_id', $this->student->getKey())
        ->where('type', NotificationType::SubscriptionActivated->value)
        ->first();

    expect(Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())
        ->where('course_id', $this->course->getKey())
        ->exists())->toBeTrue()
        ->and($notification)->not->toBeNull()
        ->and($notification->body_ar)->toContain('مواعيد مدرّسك');
});
