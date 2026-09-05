<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Events\BalanceUpdated;
use App\Modules\Payments\Events\CreditConsumed;
use App\Modules\Payments\Listeners\ChargeSeatsOnDelivery;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-006 — the declared chain, observed rather than assumed.
|
| `SessionDelivered → ChargeSessionSeats → CreditConsumed → BalanceUpdated`, in
| that order. Asserting the entries alone would pass with the chain rewired: a
| listener on the wrong event, an action called from a controller, an event
| dispatched inside the ledger's transaction. The order is what is being claimed,
| so the order is what is watched.
|
| ⚠️ THE FAKE IS PARTIAL, AND THAT IS NOT A DETAIL. `Queue::fake()` with no
| arguments sends the queued charge listener to the fake too, and every
| assertion below becomes an assertion about ZERO rows — green, and proving the
| opposite of what it says. With no fake at all, `->delay()` runs immediately on
| the `sync` connection, so CloseClassSessionJob closes the session before the
| teacher can join and the timeline collapses.
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

    /*
     | Deferral, so the SEAT is free to take and each test can then state the
     | balance it wants to reason about.
     |
     | Funding the student instead would have been the obvious move and it is the
     | wrong one here: every test below counts entries and asserts an exact
     | remaining, and three of them start from a balance they set themselves —
     | including "sitting exactly at their floor", whose whole premise is ZERO.
     | Credits added to make a booking possible would have to be subtracted from
     | three sets of expectations, which is a fixture editing the claims.
     |
     | The mode does not touch what this file measures: the charge is posted with
     | `enforceFloor: false` in every mode (R17), so the chain, the idempotency
     | key and the counters are identical. The one test that needs the prepaid
     | floor says so in its own body.
     */
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::ManualCollection->value]);

    $this->session = billableSession($this->workspace, $this->owner, $this->course);

    app(BookSeat::class)->handle($this->session->refresh(), $this->student);
    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();
});

it('runs the four links in the order the criterion names', function (): void {
    $seen = [];

    // ⚠️ The FIRST link is asserted by its WIRING, not by its position in this
    // list, and that is forced rather than lazy: a listener registered from a
    // test is appended after the provider's, so a recorder on SessionDelivered
    // always fires AFTER the charge it is supposed to precede. Recording the
    // position would therefore assert the reverse of the truth. What can be
    // proved is that the charge hangs off this event and off nothing else —
    // which is the claim — plus the order of the two links downstream of it.
    expect(Event::hasListeners(SessionDelivered::class))->toBeTrue();

    $registered = array_map(
        fn (mixed $listener): string => is_string($listener) ? $listener : $listener::class,
        Event::getRawListeners()[SessionDelivered::class] ?? [],
    );

    expect($registered)->toContain(ChargeSeatsOnDelivery::class);

    Event::listen(CreditConsumed::class, function () use (&$seen): void {
        $seen[] = 'consumed';
    });
    Event::listen(BalanceUpdated::class, function () use (&$seen): void {
        $seen[] = 'balance';
    });

    deliverBillableSession($this->session, $this->owner);

    // Consumed BEFORE balance: the entry is written first and the balance moves
    // after it. Reversed, a replayed event debits twice while the duplicate entry
    // is ignored once, and the balance stops equalling the sum of its entries
    // permanently, with nothing to notice it.
    expect($seen)->toBe(['consumed', 'balance']);
});

it('writes one consume entry per seat and moves the balance with it', function (): void {
    $balance = billingBalance($this->workspace, $this->student, $this->course);
    grantCredits($balance, 3, 'chain');

    deliverBillableSession($this->session, $this->owner);

    $entries = CreditTransaction::query()->withoutWorkspaceScope()
        ->where('type', CreditTransactionType::Consume)->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->credits)->toBe(-1)
        ->and($entries->first()->source_type)->toBe('class_session')
        ->and($entries->first()->source_id)->toBe((int) $this->session->getKey());

    // FR-004 — the displayed balance is the sum of its entries, and `consumed`
    // is the one counter a consume touches.
    $balance->refresh();

    expect($balance->remaining_credits)->toBe(2)
        ->and($balance->consumed_credits)->toBe(1)
        ->and($balance->purchased_credits)->toBe(3);
});

it('marks the session charged, which is what takes it out of the sweep', function (): void {
    expect($this->session->charged_at)->toBeNull();

    deliverBillableSession($this->session, $this->owner);

    expect($this->session->refresh()->charged_at)->not->toBeNull();
});

/*
| SC-003 — the same event, ten times, one credit.
|
| Not a hypothetical: a queue retry, an operator running the sweep beside a
| worker, and a job whose worker died after the write all replay it. The unique
| key is the guard, and it is the DATABASE's, not a count-then-insert.
*/
it('consumes once however many times the event arrives', function (): void {
    grantCredits(billingBalance($this->workspace, $this->student, $this->course), 10, 'replay');

    $delivered = deliverBillableSession($this->session, $this->owner);

    foreach (range(1, 10) as $ignored) {
        SessionDelivered::dispatch($delivered->refresh(), 1, []);
    }

    expect(CreditTransaction::query()->withoutWorkspaceScope()
        ->where('type', CreditTransactionType::Consume)->count())->toBe(1)
        ->and(billingBalance($this->workspace, $this->student, $this->course)->refresh()->remaining_credits)
        ->toBe(9);
});

/*
| R17 — the floor guards BOOKING, not the recording of a debt that has already
| happened.
|
| The session WAS taught, and 014 has already earned the teacher their fee from
| this same event. Refusing the entry would leave the platform owing money with
| no claim recorded against anyone — and exam mode makes that systematic, because
| it forces the floor to zero for every student at once.
*/
it('records the debt on a student sitting exactly at their floor', function (): void {
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::PrepaidCredits->value]);

    $balance = billingBalance($this->workspace, $this->student, $this->course);

    // Zero remaining under a prepaid mode: the floor is zero and the booking
    // guard would refuse the next seat outright. Refreshed because the counters
    // are schema defaults, so the just-created model carries nulls — a client
    // reading one of those would render a balance it cannot know is zero.
    expect($balance->refresh()->remaining_credits)->toBe(0);

    deliverBillableSession($this->session, $this->owner);

    expect($balance->refresh()->remaining_credits)->toBe(-1)
        ->and(CreditTransaction::query()->withoutWorkspaceScope()
            ->where('type', CreditTransactionType::Consume)->count())->toBe(1);
});
