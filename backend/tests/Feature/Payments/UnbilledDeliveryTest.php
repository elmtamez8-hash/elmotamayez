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

    $this->session = billableSession($this->workspace, $this->owner, $this->course);

    app(BookSeat::class)->handle($this->session->refresh(), $this->student);
    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();

    grantCredits(billingBalance($this->workspace, $this->student, $this->course), 5, 'unbilled');
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
