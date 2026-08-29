<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Jobs\ReconcileCreditBalancesJob;
use App\Modules\Payments\Models\CreditReconciliationRun;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| The two things a zero-credit charge must not break (T099).
|
| ⚠️ THE NIGHTLY RECONCILIATION IS WHY THE ENTRY EXISTS AT ALL. Its third
| invariant is «one consumption entry per seat of a charged session», and it is
| the ONE check that can see a session nobody was debited for — the ledger-vs-
| balance comparison cannot, because both sides are written by the same path in
| the same transaction and agree perfectly when neither ran.
|
| So skipping the row for a subscribed seat, which looks identical on every
| balance assertion in the suite, would report every subscribed student every
| night for ever, with nothing anywhere able to clear it.
|
| ⚠️ AND THE TEACHER IS STILL PAID. Settlement earns its fee from
| `SessionDelivered`, which knows nothing about how the student paid — a
| subscription that stopped the teacher's units would be the platform collecting
| a month and delivering lessons for free.
*/
beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    /*
    | ⚠️ THE COURSE'S TEACHER PROFILE IS POINTED AT THE OWNER, AND WITHOUT IT THE
    | ACCRUAL ASSERTION BELOW MEASURES THE FIXTURE. `courseWithRate` writes the
    | approved settlement rate against a profile of its own making, while
    | `billableSession` looks a profile up BY USER and creates a fresh one when it
    | finds none — so the session would be taught by a teacher who has no approved
    | rate, produce zero teaching units, and report «a subscription stopped the
    | teacher being paid» about a fixture that could never have paid them.
    */
    TeacherProfile::query()
        ->whereKey($this->course->teacher_profile_id)
        ->update(['user_id' => $this->owner->getKey()]);

    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::ManualCollection->value]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'session_type' => ClassSessionType::Individual,
    ]);

    Subscription::factory()->create([
        'plan_id' => $plan->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'student_user_id' => $this->student->getKey(),
    ]);

    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 3);
    // ⚠️ INDIVIDUAL, TO MATCH THE APPROVED SETTLEMENT RATE `courseWithRate`
    // WRITES. The teacher's accrual needs a rate for the session's OWN type, so a
    // group room here would produce zero units for a reason that has nothing to
    // do with subscriptions — a green «the teacher was not paid» assertion
    // measuring the fixture instead of the feature.
    $this->session->forceFill(['type' => ClassSessionType::Individual])->save();

    app(BookSeat::class)->handle($this->session, $this->student);

    /*
    | ⚠️ THE FROZEN SEAT COUNT IS STAMPED BY HAND, because `FreezeBillableSeatsJob`
    | is part of the timeline and a `->delay()` on the `sync` connection fires at
    | dispatch — long before the seat above was taken. Left at zero, the ACCRUAL
    | returns an empty array at its first guard while the charge falls back to the
    | seat holders and looks perfectly healthy: «the teacher earned nothing» would
    | be true of the fixture and say nothing about subscriptions.
    */
    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();

    deliverBillableSession($this->session->refresh(), $this->owner);
});

it('leaves the nightly reconciliation with nothing to report', function (): void {
    // The positive control is the charge itself: the seat WAS recorded, at zero.
    expect(CreditTransaction::query()
        ->withoutWorkspaceScope()
        ->where('source_id', $this->session->getKey())
        ->count())->toBe(1);

    app(ReconcileCreditBalancesJob::class)->handle();

    $run = CreditReconciliationRun::query()->latest('id')->firstOrFail();

    expect((int) $run->findings_count)->toBe(0);
});

it('still earns the teacher their unit for the hour they taught', function (): void {
    /*
    | ⚠️ TWO CONTEXTS WITH NO KEY BETWEEN THEM, AND THIS IS THE ONE BRIDGE:
    | `SessionDelivered`. What the student paid and what the teacher earns share
    | that event and nothing else — so a pricing shape on the student's side must
    | be invisible on the teacher's, and it is measured rather than assumed.
    */
    expect(TeachingUnit::query()
        ->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->count())->toBe(1);
});
