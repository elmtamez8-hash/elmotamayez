<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Jobs\ExpireSubscriptionsJob;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\EnrollmentDirectory;
use Carbon\CarbonImmutable;

/*
| One enrolment row per (workspace, course, student), and it belongs to whatever
| paid for it LAST. Before 2026-09-23 `EnrollStudent` was a bare `firstOrCreate`:
| a renewal was closed by the first month's expiry, a re-purchase after a lapse
| got the expired row back, and a finished course outlived its subscription.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'duration_days' => 30,
    ]);

    $this->buyer = User::factory()->create(['last_workspace_id' => null]);
    $this->approver = makePlatformStaff(Roles::FINANCE_ADMIN);
});

function handOverBuy(): Subscription
{
    $order = app(PurchaseSubscription::class)->handle(test()->buyer, (string) test()->plan->uuid);
    app(ApproveOrder::class)->handle($order, test()->approver);

    return Subscription::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->firstOrFail();
}

function handOverExpire(Subscription $subscription): void
{
    $yesterday = CarbonImmutable::today()->subDay();
    $subscription->forceFill(['ends_on' => $yesterday, 'effective_ends_on' => $yesterday])->save();

    app(ExpireSubscriptionsJob::class)->handle(app(DispatchNotification::class));
}

function handOverEnrollment(): Enrollment
{
    return Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', test()->buyer->getKey())
        ->where('course_id', test()->course->getKey())
        ->sole();
}

it('keeps a renewed subscription open when the first month runs out', function (): void {
    $first = handOverBuy();
    $second = handOverBuy();

    expect(handOverEnrollment()->order_id)->toBe((int) $second->order_id);

    handOverExpire($first);

    expect(handOverEnrollment()->status)->toBe('active');
});

it('reopens the course for a student who pays again after a lapse', function (): void {
    handOverExpire(handOverBuy());
    expect(handOverEnrollment()->status)->toBe('expired');

    handOverBuy();

    expect(handOverEnrollment()->status)->toBe('active');
});

it('closes a completed enrolment when its subscription ends', function (): void {
    $subscription = handOverBuy();
    handOverEnrollment()->forceFill(['status' => 'completed'])->save();

    handOverExpire($subscription);

    expect(handOverEnrollment()->status)->toBe('expired');
});

it('does not let a subscription end close a course that was granted outright over it', function (): void {
    $subscription = handOverBuy();

    app(EnrollStudent::class)->handle($this->course, $this->buyer, 'manual');
    handOverExpire($subscription);

    expect(handOverEnrollment())->source->toBe('manual')->status->toBe('active');
});

it('never puts an outright enrolment on a subscription timer', function (): void {
    app(EnrollStudent::class)->handle($this->course, $this->buyer, 'manual');

    handOverExpire(handOverBuy());

    expect(handOverEnrollment())
        ->source->toBe('manual')
        ->status->toBe('active')
        ->expires_at->toBeNull();
});

it('treats a completed enrolment as enrolled at every door', function (): void {
    handOverBuy();
    handOverEnrollment()->forceFill(['status' => 'completed'])->save();

    $directory = app(EnrollmentDirectory::class);

    expect($directory->hasActiveEnrollment($this->buyer, (int) $this->course->getKey()))->toBeTrue()
        ->and($directory->hasActiveEnrollmentInWorkspace($this->buyer, (int) $this->workspace->getKey()))->toBeTrue()
        ->and($directory->activeCourseIdsFor($this->buyer))->toContain((int) $this->course->getKey());
});
