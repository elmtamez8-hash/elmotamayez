<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Queue;

/*
| A plan is sold for a ROOM SIZE, and the booking door has to ask it that way
| (conflicts audit C7, 2026-09-23). The withholding lift and the credit hold
| asked course-level, so a group-only subscriber with no credits booked
| one-to-one lessons in a prepaid workspace, each delivery debited past the
| floor, and nothing ever refused the next one.
*/
beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class]);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::PrepaidCredits->value]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'session_type' => ClassSessionType::Group,
    ]);
    Subscription::factory()->create([
        'plan_id' => $plan->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'student_user_id' => $this->student->getKey(),
    ]);
});

function planTypeSession(ClassSessionType $type, int $seats): ClassSession
{
    $session = billableSession(test()->workspace, test()->owner, test()->course, seatsTotal: $seats);
    $session->forceFill(['type' => $type])->save();

    return $session->refresh();
}

it('books the group lesson the plan was sold for', function (): void {
    $booking = app(BookSeat::class)->handle(planTypeSession(ClassSessionType::Group, 5), $this->student);

    expect($booking->exists)->toBeTrue();
});

it('refuses a one-to-one lesson to a group-only subscriber with no credits', function (): void {
    expect(fn () => app(BookSeat::class)->handle(planTypeSession(ClassSessionType::Individual, 1), $this->student))
        ->toThrow(DomainException::class);
});
