<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CancelClassSession;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-007 — cancelled, interrupted, frozen: no credit, in all three.
|
| And the assertion is deliberately made TWICE over, on two different things:
|
|   · zero consume entries — the outcome; and
|   · `SessionDelivered` was never dispatched — the mechanism.
|
| The second is the one that carries the design. FR-024, FR-025أ and FR-025ج are
| conditions on FIRING THE EVENT, upstream in 005 — not checks written in
| Payments. Asserting only "zero entries" would stay green if someone moved those
| conditions into ChargeSessionSeats as a second copy, and the two copies would
| then have to be kept in step forever. Asserting the event proves the condition
| is still upstream.
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
    // Prepaid is the default, so a seat has to be paid for before it can be
    // taken. The subject of this file is not money; the funding is fixture.
    fundBooking($this->workspace, $this->student, $this->course);

    $this->session = billableSession($this->workspace, $this->owner, $this->course);

    app(BookSeat::class)->handle($this->session->refresh(), $this->student);
    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();

    grantCredits(billingBalance($this->workspace, $this->student, $this->course), 5, 'no-charge');
});

function consumeEntryCount(): int
{
    return CreditTransaction::query()->withoutWorkspaceScope()
        ->where('type', CreditTransactionType::Consume)->count();
}

it('charges nothing for a cancelled session', function (): void {
    Event::fake([SessionDelivered::class]);

    app(CancelClassSession::class)->handle($this->session->refresh(), 'المدرّس مريض');

    Event::assertNotDispatched(SessionDelivered::class);

    expect(consumeEntryCount())->toBe(0);
});

it('charges nothing for an interrupted session', function (): void {
    Event::fake([SessionDelivered::class]);

    // Interrupted is not terminal, so the close still runs — and still refuses,
    // because the teacher never joined and stayed.
    $this->session->refresh()->forceFill(['status' => ClassSessionStatus::Interrupted])->save();

    app(CloseClassSession::class)->handle($this->session->refresh());

    Event::assertNotDispatched(SessionDelivered::class);

    expect(consumeEntryCount())->toBe(0)
        ->and($this->session->refresh()->delivered_at)->toBeNull();
});

it('charges nothing for a session suspended inside a freeze period', function (): void {
    Event::fake([SessionDelivered::class]);

    FreezePeriod::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'student_user_id' => null,
        'starts_on' => CarbonImmutable::now()->subDay()->toDateString(),
        'ends_on' => CarbonImmutable::now()->addWeek()->toDateString(),
        'reason' => 'إجازة',
        'created_by' => $this->owner->getKey(),
    ]);

    // A session inside a freeze is suspended, not deleted (FR-043). Suspended is
    // terminal, so the close returns early and the event never fires.
    $this->session->refresh()->forceFill(['status' => ClassSessionStatus::Suspended])->save();

    app(CloseClassSession::class)->handle($this->session->refresh());

    Event::assertNotDispatched(SessionDelivered::class);

    expect(consumeEntryCount())->toBe(0);
});
