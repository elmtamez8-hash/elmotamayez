<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\Payments\Actions\AdjustCredits;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-010 · FR-032 · FR-033 — the wall, and the door out of it.
|
| Three claims, and the middle one is the one a simpler design gets wrong:
|
|   · the refusal names the NUMBER and the way to pay, not "not allowed";
|   · a course the student is PAID UP IN keeps working — withholding is per
|     course, and answering per workspace would close a course nobody owes on;
|   · the lift needs NO job and no operator. The predicate is derived, so the very
|     next attempt succeeds.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::PrepaidCredits->value]);

    $this->maths = courseWithRate((int) $this->workspace->getKey());
    $this->physics = courseWithRate((int) $this->workspace->getKey());

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->maths, $this->student);
    $this->createEnrollment($this->workspace, $this->physics, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // Paid up in physics, empty in maths.
    grantCredits(billingBalance($this->workspace, $this->student, $this->physics), 4, 'physics');
    billingBalance($this->workspace, $this->student, $this->maths);
});

/** A bookable session on one course. */
function sessionOnCourse(object $course): object
{
    return billableSession(test()->workspace, test()->owner, $course);
}

it('refuses the booking with the amount needed and where to pay', function (): void {
    $session = sessionOnCourse($this->maths);

    try {
        app(BookSeat::class)->handle($session->refresh(), $this->student);
        $this->fail('The booking should have been refused.');
    } catch (DomainException $e) {
        // FR-032 — an actionable sentence. A student who reads "غير مسموح" has
        // learned nothing they can act on, and support has learned nothing
        // either.
        expect($e->getMessage())->toContain('حصة')
            ->and($e->getMessage())->toContain('صفحة الأرصدة');
    }
});

it('leaves the course they are paid up in open', function (): void {
    // The whole reason withholding is by course. Answering per workspace closes
    // physics too, which is not a rounding error — it is taking away something
    // that was paid for.
    $session = sessionOnCourse($this->physics);

    app(BookSeat::class)->handle($session->refresh(), $this->student);

    expect($session->refresh()->seats_taken)->toBe(1);
});

it('lifts the hold on the very next attempt, with nothing run in between', function (): void {
    $first = sessionOnCourse($this->maths);

    expect(fn () => app(BookSeat::class)->handle($first->refresh(), $this->student))
        ->toThrow(DomainException::class);

    // Credits arrive. No job, no sweep, no operator clearing a flag — there is no
    // flag. The predicate simply answers differently.
    app(AdjustCredits::class)->handle(
        billingBalance($this->workspace, $this->student, $this->maths),
        CreditTransactionType::Bonus,
        2,
        'دفعة وصلت',
        'lift-hold',
    );

    $second = sessionOnCourse($this->maths);

    app(BookSeat::class)->handle($second->refresh(), $this->student);

    expect($second->refresh()->seats_taken)->toBe(1);
});

/*
| FR-031 · US2/2 · SC-004 — the prepaid wall, and the ONE case it does not cover.
|
| ⚠️ THE MISSING ROW WAS THE HOLE, AND IT WAS EVERY STUDENT'S FIRST DAY. The
| balance row is created lazily — by the first purchase, or by the first charge —
| so a newly enrolled student has none, and "no row" used to read as "nothing
| owing" in every mode. In PREPAID_CREDITS, which is the launch default, that let
| a newcomer book the whole timetable at once; the sessions were then delivered,
| the teacher earned their fee from the same event (spec 014), and only THEN was
| the row created, at −1, −2, −8. Thirty newcomers in one group class is 240
| seats taught and nothing collected.
|
| The two tests below are the same fact from both sides. The old single test
| asserted only the second one and called it the rule.
*/

it('refuses a newcomer with no balance row where credits are prepaid', function (): void {
    $newcomer = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->physics, $newcomer);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $session = sessionOnCourse($this->physics);

    // The refusal still names a number and a way to pay (SC-010): a newcomer is
    // the person most in need of being told what to do next, not least.
    try {
        app(BookSeat::class)->handle($session->refresh(), $newcomer);

        expect(false)->toBeTrue('a prepaid workspace let a booking through at zero');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('1')
            ->and($e->getMessage())->toContain('الأرصدة');
    }

    expect($session->refresh()->seats_taken)->toBe(0);
});

it('lets a newcomer with no balance row book where collection is by hand', function (): void {
    // The argument the old comment made, kept where it is true. A workspace that
    // collects cash and never sells a credit must not have its students locked
    // out by a table it never writes to — deferral is exactly the mode that says
    // "the money arrives by another route".
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::ManualCollection->value]);

    $newcomer = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->physics, $newcomer);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $session = sessionOnCourse($this->physics);

    app(BookSeat::class)->handle($session->refresh(), $newcomer);

    expect($session->refresh()->seats_taken)->toBe(1);
});

it('blocks at zero in a prepaid workspace even when the switch says remind only', function (): void {
    // FR-027 makes the zero-balance behaviour configurable; FR-014 says a
    // prepaid balance never goes below zero, full stop. Two rules meeting at one
    // predicate, and `remind` used to win — so the mode's own first sentence was
    // switched off by a dropdown, silently, for whoever changed it.
    app(BillingSettings::class)->save($this->workspace, [
        'mode' => BillingMode::PrepaidCredits->value,
        'zero_balance_behavior' => 'remind',
    ]);

    $emptied = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->maths, $emptied);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // A row that exists and holds nothing — the case `remind` was written for.
    billingBalance($this->workspace, $emptied, $this->maths);

    $session = sessionOnCourse($this->maths);

    expect(fn () => app(BookSeat::class)->handle($session->refresh(), $emptied))
        ->toThrow(DomainException::class);

    expect($session->refresh()->seats_taken)->toBe(0);
});
