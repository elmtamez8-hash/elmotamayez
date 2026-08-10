<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\Payments\Actions\ChargeSessionSeats;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| NFR-012 — what a group class costs to bill.
|
| ⚠️ THE ONE PATH THAT SCALES WITH CLASS SIZE, AND THE ONE NOTHING MEASURED.
| BalanceQueryBudgetTest covers `/manage/billing/students`, which was already
| fixed-cost; LiveSessions/QueryBudgetTest was written before withholding entered
| BookingEligibility and never reaches a charge. So the seat loop — the only
| billing code whose work is O(students in the room) — shipped with no budget on
| it at all, and grew a lazy-loaded `workspaces` row per seat, twice more per
| seat through `refresh()` and the blocked check, plus a consent read per seat.
|
| Asserted as a DIFFERENCE, not a ceiling. A ceiling drifts upward one commit at
| a time and each rise looks reasonable; the claim here is structural — the
| billing mode, the zero-balance behaviour and the exam window are facts about
| the workspace and the moment, and thirty students share all three. Adding
| students must therefore add a bounded amount of work per student and nothing
| else, so eight seats cost exactly four seats' work plus four students'.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
});

/** A delivered session with `$seats` funded students holding seats. */
function chargeableSessionWith(int $seats): object
{
    $test = test();
    $session = billableSession($test->workspace, $test->owner, $test->course, $seats);

    foreach (range(1, $seats) as $ignored) {
        $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
        $test->createEnrollment($test->workspace, $test->course, $student);
        $test->setCurrentWorkspace($test->workspace, $test->owner);
        fundBooking($test->workspace, $student, $test->course);

        app(BookSeat::class)->handle($session->refresh(), $student);
    }

    $test->setCurrentWorkspace($test->workspace, $test->owner);
    $session->refresh()->forceFill(['billable_seats' => $seats])->save();

    return $session->refresh();
}

/**
 * How many times the charge asked about facts that belong to the WORKSPACE.
 *
 * Counted by table rather than in total, and that is the point. The ledger's own
 * per-seat work — read the balance, write the entry, draw the lot, move the
 * counters, record the allocation — is irreducible and genuinely grows with the
 * room; a total-query ceiling would mix the two and drift upward one reasonable
 * commit at a time. What must NOT grow is the number of times we ask who the
 * teacher is, how they collect, what happens at zero, and whether exams are on.
 *
 * @return array{shared: int, total: int}
 */
function chargeCost(object $session, int $seats): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    app(WorkspaceContext::class)->forWorkspace(
        test()->workspace,
        fn () => app(ChargeSessionSeats::class)->handle($session, $seats),
    );

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $shared = 0;

    foreach ($log as $query) {
        foreach (['"workspaces"', '"exam_mode_windows"', '"platform_settings"', '"terms_consents"'] as $table) {
            if (str_contains((string) $query['query'], $table)) {
                $shared++;

                break;
            }
        }
    }

    return ['shared' => $shared, 'total' => count($log)];
}

it('asks the workspace its questions once, however full the room', function (): void {
    $small = chargeableSessionWith(2);
    $large = chargeableSessionWith(8);

    $twoSeats = chargeCost($small, 2);
    $eightSeats = chargeCost($large, 8);

    // ⚠️ NO GROWTH AT ALL, NOT MERELY BOUNDED GROWTH. Four times as many
    // students must ask these four questions no more often — the billing mode,
    // the zero-balance behaviour, the workspace row and the open exam window are
    // one fact each about a workspace and a moment, and thirty students in one
    // class share every one of them. Before this was measured, three of the four
    // were resolved inside the seat loop: 21 reads for eight seats against 10
    // for two.
    //
    // `<=` rather than `=` because the platform-settings cache is warm by the
    // second measurement, so the larger room legitimately asks one FEWER. An
    // equality here would fail on an optimisation.
    expect($eightSeats['shared'])->toBeLessThanOrEqual($twoSeats['shared']);

    /*
    | The per-seat cost that remains is the ledger's own work, and it is allowed
    | to grow with the room — one student's charge cannot be another's. Thirteen
    | today, itemised so a rise has to be argued rather than absorbed:
    |
    |   the entry INSERT and its read-back · the lot SELECT, its conditional
    |   UPDATE and the allocation INSERT · the balance's incrementEach · the
    |   remaining-credits read that lets a new lot settle an old debt · two
    |   conditional UPDATEs ranking the alert tier, two more moving
    |   `negative_since` · and the attribute refresh the announcement needs.
    |
    | Fourteen is the tripwire: one spare, so a genuine addition trips it.
    */
    expect(intdiv($eightSeats['total'] - $twoSeats['total'], 6))->toBeLessThanOrEqual(14);
});

it('charges every seat, which is what makes the count above meaningful', function (): void {
    // A budget test that measured a loop doing nothing would pass forever.
    $session = chargeableSessionWith(4);

    $entries = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): array => app(ChargeSessionSeats::class)->handle($session, 4),
    );

    expect($entries)->toHaveCount(4);
});
