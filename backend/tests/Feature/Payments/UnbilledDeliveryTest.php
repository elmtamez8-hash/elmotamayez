<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\Payments\Actions\ChargeSessionSeats;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Jobs\ChargeUnbilledDeliveriesJob;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| quickstart §١٣ · research › R17 — the session that was delivered and never
| charged.
|
| The reason this job exists rather than a retry: CloseClassSession returns early
| on a terminal status, so `SessionDelivered` fires exactly ONCE in a session's
| life. A queue outage, a worker killed mid-listener, a throw in a listener ahead
| of it — any of those and no second dispatch is ever coming. And because
| withholding is DERIVED from the balance rather than stored, the student's
| record stays clean and they carry on booking on credits they already spent.
|
| The outage is simulated by faking `SessionDelivered` itself — a PARTIAL fake,
| so every other event still runs. Faking the listener's queue does NOT work and
| the reason is worth writing down: a queued listener is pushed as Laravel's own
| `CallQueuedListener` wrapper, so `Queue::fake([ChargeSeatsOnDelivery::class])`
| matches nothing, the charge runs on `sync`, and the test asserts its repair
| against a session that was never broken.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // BEFORE the booking, not after it. The credits were always here; they used
    // to be granted below the seat, which only worked while a prepaid workspace
    // let an empty balance book. The order is now the real one — buy, then book
    // — and the numbers the assertions use are unchanged.
    grantCredits(billingBalance($this->workspace, $this->student, $this->course), 5, 'unbilled');

    $this->session = billableSession($this->workspace, $this->owner, $this->course);

    app(BookSeat::class)->handle($this->session->refresh(), $this->student);
    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();
});

it('charges a delivered session the queue never got to, exactly once', function (): void {
    Event::fake([SessionDelivered::class]);

    deliverBillableSession($this->session, $this->owner);

    // The outage: delivered, and nothing charged.
    expect($this->session->refresh()->delivered_at)->not->toBeNull()
        ->and($this->session->charged_at)->toBeNull()
        ->and(CreditTransaction::query()->withoutWorkspaceScope()
            ->where('type', CreditTransactionType::Consume)->count())->toBe(0);

    app(ChargeUnbilledDeliveriesJob::class)->handle(
        app(WorkspaceContext::class),
        app(ChargeSessionSeats::class),
    );

    expect(CreditTransaction::query()->withoutWorkspaceScope()
        ->where('type', CreditTransactionType::Consume)->count())->toBe(1)
        ->and($this->session->refresh()->charged_at)->not->toBeNull()
        ->and(billingBalance($this->workspace, $this->student, $this->course)->refresh()->remaining_credits)
        ->toBe(4);
});

it('writes nothing on a second run', function (): void {
    Event::fake([SessionDelivered::class]);

    deliverBillableSession($this->session, $this->owner);

    $run = fn (): mixed => app(ChargeUnbilledDeliveriesJob::class)->handle(
        app(WorkspaceContext::class),
        app(ChargeSessionSeats::class),
    );

    $run();
    $run();

    // Two guards, and both are asserted because either alone would let a
    // regression through: `charged_at` keeps the sweep from looking again, and
    // the unique key keeps a second look from writing. An operator running the
    // sweep beside a recovered worker exercises the second one.
    expect(CreditTransaction::query()->withoutWorkspaceScope()
        ->where('type', CreditTransactionType::Consume)->count())->toBe(1)
        ->and(billingBalance($this->workspace, $this->student, $this->course)->refresh()->remaining_credits)
        ->toBe(4);
});

it('leaves an undelivered session alone', function (): void {
    // Never delivered means never owed. A sweep keyed on `charged_at IS NULL`
    // alone would bill every cancelled and every unheld session on the calendar.
    app(ChargeUnbilledDeliveriesJob::class)->handle(
        app(WorkspaceContext::class),
        app(ChargeSessionSeats::class),
    );

    expect(CreditTransaction::query()->withoutWorkspaceScope()
        ->where('type', CreditTransactionType::Consume)->count())->toBe(0)
        ->and($this->session->refresh()->charged_at)->toBeNull();
});

/*
| A6 — a session whose frozen seat count never got written must not be stamped
| "charged" over a room full of students.
|
| ⚠️ THE STAMP IS WHAT REMOVES A SESSION FROM THE SWEEP, SO STAMPING IT UNCHARGED
| CLOSES THE ONLY REPAIR PATH. `billable_seats` is nullable — it is written once,
| at the cancellation deadline — and the sweep coerces null to zero because "zero
| is the honest reading of a deadline that never ran". Zero is an honest reading
| of the SEAT COUNT. It is not an honest reading of `charged_at`, which the
| migration defines as "the charge ran to completion".
|
| So a queue outage that dropped the deadline job produced: five students taught,
| nobody debited, the session marked done, and — because
| ReconcileCreditBalancesJob looks for one entry per seat of a CHARGED session —
| a finding on that session every night thereafter, permanently, with no
| mechanism anywhere able to clear it. And silently: the mismatch warning one
| branch below never ran, because this branch returns first.
|
| The seat holders are the fact that survives. The frozen count is an optimisation
| of it, and when the optimisation is missing the fact is still there to be read.
*/
it('charges the seats it can see when the frozen count never got written', function (): void {
    Event::fake([SessionDelivered::class]);

    // The deadline job never ran: the column is still null.
    $this->session->forceFill(['billable_seats' => null])->save();

    deliverBillableSession($this->session, $this->owner);

    app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn () => app(ChargeSessionSeats::class)->handle(
            $this->session->refresh(),
            $this->session->billable_seats ?? 0,
        ),
    );

    expect(CreditTransaction::query()->withoutWorkspaceScope()
        ->where('type', CreditTransactionType::Consume)->count())->toBe(1)
        ->and($this->session->refresh()->charged_at)->not->toBeNull();
});

it('stamps a session nobody booked, because there is nothing to repair', function (): void {
    Event::fake([SessionDelivered::class]);

    // The other reading of zero, and here it is the true one: the seats were
    // released before the deadline, so no student owes anything. Stamping is
    // right — leaving it null would have the sweep pick this session up every
    // fifteen minutes for the life of the product.
    $this->session->bookings()->delete();
    $this->session->forceFill(['billable_seats' => 0])->save();

    deliverBillableSession($this->session, $this->owner);

    app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn () => app(ChargeSessionSeats::class)->handle($this->session->refresh(), 0),
    );

    expect(CreditTransaction::query()->withoutWorkspaceScope()
        ->where('type', CreditTransactionType::Consume)->count())->toBe(0)
        ->and($this->session->refresh()->charged_at)->not->toBeNull();
});
