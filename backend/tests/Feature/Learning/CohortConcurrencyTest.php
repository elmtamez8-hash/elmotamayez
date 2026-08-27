<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Actions\DecideTransferRequest;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\RequestTransfer;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\CohortRefusal;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
| SC-008 · SC-008أ — one open membership per (student, course), and one seat to
| one of two requests, under real concurrency.
|
| ⚠️ A SEQUENTIAL TEST PASSES AGAINST A BUILD WITH NO CLAIM IN IT AT ALL. Called
| twice in a row, the second `JoinCohort` returns at the `hasOpenMembership`
| line one step ABOVE the claim and never reaches it — so «join twice ⇒ one
| membership» is green whether or not the guard exists. The same shape nearly
| shipped `OpenBroadcastRoom` without its claim.
|
| ⚠️ SO THE RIVAL RUNS BETWEEN THE ACTION'S READ AND ITS TRANSACTION.
| `DB::beforeExecuting` fires a callback in exactly that window — no threads, no
| sleeps, and no seam added to production code for a test's benefit. What it
| simulates is the only thing that matters: two workers whose «has this student a
| membership?» both answered no before either wrote one.
|
| ⚠️ AND IT CANNOT BE FIRED INSIDE THE WRITER'S OWN TRANSACTION, which is a
| property of the harness rather than of the code. Every test here runs on ONE
| in-memory SQLite connection, so a rival started inside an open transaction joins
| that transaction and is rolled back with it — the "second worker" would vanish
| along with the first, and the assertion would be about neither. The window
| before the transaction is a real one in production and is the one that is
| reachable from here.
*/

function interposeOnce(string $needle, Closure $rival): void
{
    $fired = false;

    DB::beforeExecuting(function (string $query) use (&$fired, $needle, $rival): void {
        // The guard is not decoration: the rival issues queries of its own, and
        // without it this recurses until the stack gives out.
        if ($fired || ! str_contains($query, $needle)) {
            return;
        }

        $fired = true;

        $rival();
    });
}

it('gives one membership to two joins that both read an empty slate', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    $join = app(JoinCohort::class);
    $rivalRan = false;

    // Fires while the FIRST call is between reading "no membership" and opening
    // its transaction — the window a `count()`-then-`insert()` guard leaves open.
    interposeOnce('exists(select * from "cohort_memberships"', function () use ($join, $fx, &$rivalRan): void {
        $rivalRan = true;

        try {
            $join->handle($fx['b'], $fx['student']);
        } catch (CohortRefusal) {
            // One of the two has to lose; which one is not the assertion.
        }
    });

    try {
        $join->handle($fx['a'], $fx['student']);
    } catch (CohortRefusal) {
        // Likewise.
    }

    /*
    | ⚠️ THE CONTROL, AND IT IS NOT CEREMONY. If the needle stops matching — a
    | Laravel release rewords `exists()`, or the Action's read moves — the hook
    | never fires, ONE join runs, and every assertion below is satisfied by a
    | build with no concurrency guard whatsoever. A race test that silently
    | stopped racing is the worst kind of green.
    */
    expect($rivalRan)->toBeTrue();

    $open = CohortMembership::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $fx['student']->getKey())
        ->where('course_id', $fx['course']->getKey())
        ->whereNull('closed_at')
        ->count();

    expect($open)->toBe(1);

    // ⚠️ AND THE LOSER'S SEAT IS GIVEN BACK. A group that reads full with an
    // empty chair in it is the failure this assertion exists for — the count is
    // the number of people in the room, and nobody would ever find out.
    $seats = Cohort::query()->withoutWorkspaceScope()
        ->whereIn('id', [$fx['a']->getKey(), $fx['b']->getKey()])
        ->sum('members_count');

    expect((int) $seats)->toBe(1);
});

/*
| ⚠️ AND THE INDEX IS THE GUARD, so it is asserted directly rather than inferred
| from an Action's behaviour. Everything above runs through code that could be
| refusing for reasons of its own; this asks the database the question SC-008 is
| actually about, and it is the assertion that fails if `closed_slot` is ever
| "simplified" into a unique carrying nullable `closed_at` — which would not bite
| at all, because NULL never equals NULL on either engine.
*/
it('refuses a second open membership at the database, sentinel and all', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);

    $second = fn (): CohortMembership => CohortMembership::query()->create([
        'workspace_id' => $fx['workspace']->getKey(),
        'cohort_id' => $fx['b']->getKey(),
        'course_id' => $fx['course']->getKey(),
        'student_user_id' => $fx['student']->getKey(),
        'joined_at' => now(),
    ]);

    expect($second)->toThrow(UniqueConstraintViolationException::class);
});

it('lets one of two pending requests take the last seat and refuses the other', function (): void {
    $fx = cohortFixture(null, 1);
    $this->asGuest();

    $second = User::factory()->create();

    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx, $second): void {
        Enrollment::create([
            'workspace_id' => $fx['workspace']->getKey(),
            'course_id' => $fx['course']->getKey(),
            'student_user_id' => $second->getKey(),
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);
    });

    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);
    app(JoinCohort::class)->handle($fx['a'], $second);

    $one = app(RequestTransfer::class)->handle($fx['b'], $fx['student']);
    $two = app(RequestTransfer::class)->handle($fx['b'], $second);

    $decide = app(DecideTransferRequest::class);

    $decide->handle($one, $fx['owner'], true);

    // ⚠️ THE CAPACITY IS MEASURED HERE, NOT AT SUBMISSION. Both requests were
    // valid the day they were made; the second one is refused because the seat
    // went while it waited — which is what FR-028ز asks for, and what measuring
    // at submission would have got wrong by accepting both.
    expect(fn () => $decide->handle($two, $fx['owner'], true))
        ->toThrow(CohortRefusal::class);

    // And the loser keeps everything they had.
    expect(app(CohortDirectory::class)
        ->openMembershipCohortId($second, (int) $fx['course']->getKey()))
        ->toBe((int) $fx['a']->getKey());

    expect($two->refresh()->status)->toBe(CohortTransferRequest::PENDING);
});
