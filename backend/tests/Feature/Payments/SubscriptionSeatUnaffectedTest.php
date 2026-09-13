<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\SessionUnlock;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\SessionContentAccess;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · T080 · FR-012 — ٠٣٥ CHANGES NOTHING FOR A SUBSCRIBER.
|
| ⛔ TWO CONDITIONS SPELLED OUT, BECAUSE WITHOUT EITHER THE FILE IS VACUOUS.
|
|  · THE SUBSCRIPTION ACTUALLY COVERED THE SEAT — proved by the subscription's
|    own uuid on the zero-credit entry, not by the zero. «Nothing was charged» is
|    equally true of a student with no balance, of a delivery that never fired,
|    and of an Action that returned at its first guard.
|  · THE STUDENT DID NOT ATTEND. «The content is open» is trivially true of
|    somebody who was in the room — that is the ordinary charged path, and it
|    would pass over a build with no subscription branch in it at all.
|
| ⚠️ AND THE CONTROL IS A NON-SUBSCRIBER IN THE SAME ROOM, exempt in the same
| way. Their hour stays SHUT, which is what says the opening came from the
| subscription rather than from the exemption. This is also the arm T043 shipped
| without a test: a subscriber holds no credits by design, so a lock priced in
| credits is a lock with no way out.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    // Deferral, so a seat can be taken at a zero balance: a subscriber owns no
    // credits, and the subject here is the charge and not the booking guard.
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::ManualCollection->value]);

    $this->subscriber = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->payer = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->createEnrollment($this->workspace, $this->course, $this->subscriber);
    $this->createEnrollment($this->workspace, $this->course, $this->payer);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // ⚠️ THE CONTROL IS FUNDED AND THE SUBSCRIBER IS NOT, which is the shape of
    // the product: a subscriber owns no credits by design, so booking freezes
    // nothing for them — and the payer beside them must be able to book at all,
    // or the whole comparison collapses into two students who never had a seat.
    fundBooking($this->workspace, $this->payer, $this->course, 1);

    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 5);
    $this->session->forceFill(['type' => ClassSessionType::Group])->save();

    $plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'session_type' => ClassSessionType::Group,
    ]);

    $this->subscription = Subscription::factory()->create([
        'plan_id' => $plan->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'student_user_id' => $this->subscriber->getKey(),
    ]);
});

/** Both seats taken, both excused before the room closed, and nobody attends. */
function unaffectedDelivery(object $test): void
{
    app(BookSeat::class)->handle($test->session->refresh(), $test->subscriber);
    app(BookSeat::class)->handle($test->session->refresh(), $test->payer);

    // Notice given: the shortfall costs neither of them a credit, which is what
    // puts both on the exempt side and leaves the subscription as the only
    // difference between them.
    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $test->session->getKey())
        ->update(['excused_at' => now(), 'excused_by_user_id' => $test->owner->getKey()]);

    $test->session->refresh()->forceFill(['billable_seats' => 2])->save();

    // An empty attendee list: neither of them was ever in the room.
    deliverBillableSession($test->session->refresh(), $test->owner, []);
}

it('charges a covered seat nothing and says on the entry that the subscription is why', function (): void {
    unaffectedDelivery($this);

    $entry = CreditTransaction::query()
        ->withoutWorkspaceScope()
        ->where('source_type', 'class_session')
        ->where('source_id', $this->session->getKey())
        ->get()
        ->first(fn (CreditTransaction $row): bool => (int) $row->balance->student_user_id === (int) $this->subscriber->getKey());

    // The first condition. A zero with nothing beside it does not say the
    // subscription covered anything — and the ledger is append-only, so a reason
    // worked out afterwards has nowhere to go.
    expect($entry)->not->toBeNull()
        ->and((int) $entry->credits)->toBe(0)
        ->and($entry->meta['subscription_uuid'] ?? null)->toBe($this->subscription->uuid);

    expect((int) billingBalance($this->workspace, $this->subscriber, $this->course)->remaining_credits)->toBe(0);
});

it('opens the hour to a subscriber who never turned up, and leaves it shut for the payer beside them', function (): void {
    unaffectedDelivery($this);

    $access = app(SessionContentAccess::class);
    $sessionId = (int) $this->session->getKey();

    expect($access->mayOpenSessionContent($this->subscriber, $sessionId))->toBeTrue()
        ->and($access->mayOpenSessionContent($this->payer, $sessionId))->toBeFalse();

    // And it is opened by a ROW naming the reason. A zero-credit unlock with no
    // reason cannot be told from a defect a month later, which is why the column
    // is not decoration.
    $unlock = SessionUnlock::query()
        ->withoutWorkspaceScope()
        ->where('class_session_id', $sessionId)
        ->where('student_user_id', $this->subscriber->getKey())
        ->sole();

    expect($unlock->reason)->toBe(SessionUnlock::REASON_SUBSCRIPTION)
        ->and((int) $unlock->credits_charged)->toBe(0);
});

it('leaves no credit frozen against a seat that never cost one', function (): void {
    unaffectedDelivery($this);

    // The subscriber's booking froze nothing to begin with (the adapter reads
    // the same coverage predicate the withholding does), so the counter has to
    // be zero at both ends of the hour rather than merely settled at the end.
    expect((int) billingBalance($this->workspace, $this->subscriber, $this->course)->held_credits)->toBe(0);
});
