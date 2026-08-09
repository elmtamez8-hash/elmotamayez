<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\Payments\Actions\AdjustCredits;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\Roles;
use DomainException;
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

it('does not withhold a student who simply has no balance row yet', function (): void {
    // Withholding is a statement about a balance that RAN OUT. Reading the
    // absence of a lazily-created row as "owing" would lock out every student on
    // their first day — including in a workspace that collects by hand and never
    // sells a credit at all.
    $newcomer = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->physics, $newcomer);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $session = sessionOnCourse($this->physics);

    app(BookSeat::class)->handle($session->refresh(), $newcomer);

    expect($session->refresh()->seats_taken)->toBe(1);
});
