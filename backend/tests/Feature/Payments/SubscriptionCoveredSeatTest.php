<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\AccountStanding;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-008 — a subscribed seat consumes ZERO credits (T098 · FR-028).
|
| ⚠️ WITH A POSITIVE CONTROL IN THE SAME SESSION, AND THAT IS THE WHOLE POINT OF
| THE FILE. «The subscriber's balance did not move» is perfectly true of a
| fixture where the delivery event never fired, where the charge listener was
| swallowed by a bare `Queue::fake()`, or where `ChargeSessionSeats` returned at
| its first guard. A NON-subscriber sitting in the same room, charged exactly one
| credit by the same run of the same Action, is what makes the zero mean
| something.
|
| ⚠️ AND THE ENTRY MUST EXIST. Skipping the row instead of writing a zero would
| look identical on every balance assertion — and would break
| `ReconcileCreditBalancesJob`'s «one consumption entry per seat of a charged
| session», nightly, for every subscribed student, permanently. That invariant is
| measured for real in SubscriptionReconcileTest; the row is counted here.
|
| ⚠️ THE FAKE IS PARTIAL. `Queue::fake()` with no arguments swallows the queued
| charge listener and turns every count below into a confident claim about an
| empty table; no fake at all lets `->delay()` run immediately on `sync`, so the
| close job ends the session before the teacher can join.
*/
beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    // Deferral, so a seat can be taken at a zero balance and the arithmetic
    // below is about the CHARGE rather than about whether booking was allowed.
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::ManualCollection->value]);

    $this->subscriber = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->payer = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->createEnrollment($this->workspace, $this->course, $this->subscriber);
    $this->createEnrollment($this->workspace, $this->course, $this->payer);

    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // ⚠️ THE ROOM SIZE IS STATED, NOT INHERITED FROM THE FACTORY. The plan's
    // `session_type` has to match it, so a fixture that leaves the default
    // implicit is a fixture whose central assertion flips the day somebody
    // changes that default — and the flip is silent, because both directions
    // produce a perfectly plausible number.
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 5);
    $this->session->forceFill(['type' => ClassSessionType::Group])->save();
});

function subscribePlan(mixed $student, mixed $workspace, ClassSessionType $type = ClassSessionType::Group): Subscription
{
    $plan = Plan::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'session_type' => $type,
    ]);

    return Subscription::factory()->create([
        'plan_id' => $plan->getKey(),
        'workspace_id' => $workspace->getKey(),
        'student_user_id' => $student->getKey(),
    ]);
}

it('charges the unsubscribed student one credit and the subscriber none', function (): void {
    subscribePlan($this->subscriber, $this->workspace);

    app(BookSeat::class)->handle($this->session, $this->subscriber);
    app(BookSeat::class)->handle($this->session->refresh(), $this->payer);

    deliverBillableSession($this->session->refresh(), $this->owner);

    $entries = CreditTransaction::query()
        ->withoutWorkspaceScope()
        ->where('source_type', 'class_session')
        ->where('source_id', $this->session->getKey())
        ->get();

    // ⚠️ TWO ROWS, NOT ONE. The subscribed seat is RECORDED, at zero.
    expect($entries)->toHaveCount(2);

    $subscriberEntry = $entries->first(
        fn (CreditTransaction $entry): bool => (int) $entry->balance->student_user_id === (int) $this->subscriber->getKey(),
    );
    $payerEntry = $entries->first(
        fn (CreditTransaction $entry): bool => (int) $entry->balance->student_user_id === (int) $this->payer->getKey(),
    );

    expect((int) $subscriberEntry->credits)->toBe(0)
        ->and($subscriberEntry->type)->toBe(CreditTransactionType::Consume)
        ->and((int) $payerEntry->credits)->toBe(-1);

    expect((int) billingBalance($this->workspace, $this->subscriber, $this->course)->remaining_credits)->toBe(0)
        ->and((int) billingBalance($this->workspace, $this->payer, $this->course)->remaining_credits)->toBe(-1);
});

it('says on the entry WHY it cost nothing', function (): void {
    // A zero-credit Consume with nothing beside it is indistinguishable from a
    // bug to whoever reads the ledger next — and the ledger is append-only, so a
    // reason worked out later has nowhere to go.
    $subscription = subscribePlan($this->subscriber, $this->workspace);

    app(BookSeat::class)->handle($this->session, $this->subscriber);
    deliverBillableSession($this->session->refresh(), $this->owner);

    $entry = CreditTransaction::query()
        ->withoutWorkspaceScope()
        ->where('source_id', $this->session->getKey())
        ->firstOrFail();

    expect($entry->meta['subscription_uuid'] ?? null)->toBe($subscription->uuid);
});

it('does NOT cover a session whose room size the plan was not priced for', function (): void {
    /*
    | ⚠️ THE USER'S OWN SENTENCE, MEASURED: «سعر كل مدرس يختلف باختلاف المادة
    | والسنة الدراسية وعدد أفراد المجموعة». Subject and year come from the course;
    | ROOM SIZE comes from nowhere but this column. A group-priced plan — the
    | cheap one — covering one-to-one hours at zero credits is the leak.
    */
    subscribePlan($this->subscriber, $this->workspace, ClassSessionType::Individual);

    // The session is a group room; the plan was priced for one-to-one.
    app(BookSeat::class)->handle($this->session, $this->subscriber);
    deliverBillableSession($this->session->refresh(), $this->owner);

    $entry = CreditTransaction::query()
        ->withoutWorkspaceScope()
        ->where('source_id', $this->session->getKey())
        ->firstOrFail();

    expect((int) $entry->credits)->toBe(-1);
});

it('does NOT cover another teacher\'s session', function (): void {
    [$other] = $this->createWorkspaceWithOwner();

    subscribePlan($this->subscriber, $other);

    app(BookSeat::class)->handle($this->session, $this->subscriber);
    deliverBillableSession($this->session->refresh(), $this->owner);

    expect((int) CreditTransaction::query()
        ->withoutWorkspaceScope()
        ->where('source_id', $this->session->getKey())
        ->value('credits'))->toBe(-1);
});

it('stops covering once the subscription has expired', function (): void {
    $plan = Plan::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    Subscription::factory()->expired()->create([
        'plan_id' => $plan->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'student_user_id' => $this->subscriber->getKey(),
    ]);

    app(BookSeat::class)->handle($this->session, $this->subscriber);
    deliverBillableSession($this->session->refresh(), $this->owner);

    expect((int) CreditTransaction::query()
        ->withoutWorkspaceScope()
        ->where('source_id', $this->session->getKey())
        ->value('credits'))->toBe(-1);
});

it('lets a subscriber with an empty balance through the money door, and stops the same student without one', function (): void {
    /*
    | ⚠️ FR-026's OTHER HALF, AND THE ONE THAT WOULD MAKE THE FEATURE USELESS IF
    | IT WERE MISSING. A subscriber's credit balance is legitimately zero and
    | stays zero — their seats cost nothing — so the withholding predicate refuses
    | the very first booking of a month they just paid for, with «رصيدك لا يكفي»,
    | unless the subscription lifts it.
    |
    | Asked through `AccountStanding`, which is the shared contract
    | `BookingEligibility` consults at BOTH doors (booking, and entering the room)
    | and Media consults before it will play a high-value asset. One seam, three
    | doors — and the negative control beside it is the same student, same course,
    | same empty balance, without the subscription.
    */
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::PrepaidCredits->value]);

    $standing = app(AccountStanding::class);
    $courseId = (int) $this->course->getKey();

    expect($standing->isWithheld($this->payer, $courseId))->toBeTrue();

    subscribePlan($this->subscriber, $this->workspace);

    expect($standing->isWithheld($this->subscriber, $courseId))->toBeFalse();
});
