<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Payments\Actions\ManageExamModeWindow;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Events\AccessRestored;
use App\Modules\Payments\Events\AccessWithheld;
use App\Modules\Payments\Models\ExamModeWindow;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\AccountStanding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-013 · FR-046 · FR-047 — the seasonal peak, governed.
|
| Exam season is where the platform's money goes missing: the papers are sat, the
| term ends, and whatever was owed leaves with the student. So for a declared
| period the floor is forced to zero — credits in hand, whatever ceiling was
| earned.
|
| Three claims, failing in three directions:
|
|   · inside the window a negative balance books NOTHING, however generous the
|     limit — the ceiling is not consulted at all;
|   · a seat taken before the window opened SURVIVES it (FR-047). The alternative
|     is a teacher switching exam mode on and emptying their own timetable;
|   · the window ends and the mode returns on its own — no job, no stored flag,
|     and the day after the last day is already unrestricted.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // A deferring mode with a real ceiling: without one, exam mode changes
    // nothing and every assertion below passes vacuously.
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::ManualCollection->value]);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->balance = billingBalance($this->workspace, $this->student, $this->course)->refresh();

    // Room to defer three sessions — the thing exam mode takes away. The consent
    // is part of the fixture and not decoration: since US9 the floor asks for it
    // too, so a ceiling without one defers nothing and every assertion below
    // would pass for the wrong reason.
    TermsConsent::factory()->create([
        'user_id' => $this->student->getKey(),
        'student_user_id' => $this->student->getKey(),
    ]);

    $this->balance->forceFill(['credit_limit_credits' => 3])->save();
});

function openWindow(int $fromDays = 0, int $toDays = 7): ?ExamModeWindow
{
    return app(ManageExamModeWindow::class)->handle(
        test()->workspace,
        CarbonImmutable::now()->addDays($fromDays),
        CarbonImmutable::now()->addDays($toDays),
        test()->owner,
    );
}

function examModeOperator(): User
{
    $test = test();

    app(PermissionRegistrar::class)->setPermissionsTeamId($test->workspace->getKey());

    $operator = $test->addWorkspaceMember($test->workspace, Roles::TENANT_OWNER);
    $operator->givePermissionTo(Permissions::BILLING_EXAM_MODE_MANAGE);
    $test->setCurrentWorkspace($test->workspace, $operator);

    return $operator;
}

it('refuses a booking on an empty balance inside the window, whatever the limit', function (): void {
    $session = billableSession($this->workspace, $this->owner, $this->course);

    // Outside the window the ceiling does its job: zero credits, three of room.
    app(BookSeat::class)->handle($session->refresh(), $this->student);

    expect(SessionBooking::query()->withoutWorkspaceScope()->count())->toBe(1);

    openWindow();

    $next = billableSession($this->workspace, $this->owner, $this->course);

    // Inside it the ceiling is not consulted at all — the floor is zero and the
    // balance is below it.
    expect(fn () => app(BookSeat::class)->handle($next->refresh(), $this->student))
        ->toThrow(DomainException::class);

    // FR-047 — and the seat taken a moment ago is still there. Opening the window
    // wrote one row; it cancelled nothing.
    expect(SessionBooking::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('lets a paid-up student book straight through it', function (): void {
    openWindow();

    grantCredits($this->balance, 2, 'exam-season');

    $session = billableSession($this->workspace, $this->owner, $this->course);

    app(BookSeat::class)->handle($session->refresh(), $this->student);

    // The window is not a freeze: it removes DEFERRAL, not booking. A student who
    // paid carries on exactly as before, which is the difference between this and
    // 005's freeze period.
    expect(SessionBooking::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('returns the mode on its own the day after the last one (FR-047)', function (): void {
    // A window that ends today. The boundary is the assertion: `ends_on` is a
    // DATE and coverage is a date-string comparison, so tomorrow is outside it
    // with nothing to run and no flag to clear.
    openWindow(0, 0);

    expect(app(AccountStanding::class)->isWithheld($this->student, (int) $this->course->getKey()))->toBeTrue();

    $this->travel(1)->days();

    // ⚠️ THE ONE A `<=` ON A TIMESTAMP GETS WRONG. Both columns here are dates, so
    // `ends_on >= today` is right — but the same comparison against a TIMESTAMP
    // column silently drops everything after midnight on the last day, which is
    // the boundary FreezePeriod::covering() had to be fixed for in 005.
    expect(app(AccountStanding::class)->isWithheld($this->student, (int) $this->course->getKey()))->toBeFalse();
});

it('does not reach into next month with a window that ended last week', function (): void {
    openWindow(-14, -7);

    expect(app(AccountStanding::class)->isWithheld($this->student, (int) $this->course->getKey()))->toBeFalse();
});

// The flip, and who hears about it (T117's third site) -------------------------

it('tells the student it just stopped, and tells them only that', function (): void {
    Event::fake([AccessWithheld::class, AccessRestored::class]);

    // Inside their ceiling and free to book — until the window opens.
    openWindow();

    // ⚠️ NAMED, not merely counted. `assertDispatched(AccessWithheld::class)`
    // passes if ANY balance in the workspace flipped, which is one seeded row
    // away from proving nothing about the person it was supposed to reach.
    Event::assertDispatched(
        AccessWithheld::class,
        fn (AccessWithheld $event): bool => $event->balance->student_user_id === $this->student->getKey(),
    );

    // And nothing in the other direction. A before-map keyed or defaulted wrongly
    // would announce the opposite event to the same people, and an assertion that
    // only checks the expected one reads green through exactly that defect.
    Event::assertNotDispatched(AccessRestored::class);
});

it('tells the student it just released, and tells them only that', function (): void {
    openWindow();

    // Faked AFTER the open, so what is measured is the close alone.
    Event::fake([AccessWithheld::class, AccessRestored::class]);

    app(ManageExamModeWindow::class)->handle($this->workspace, performedBy: $this->owner);

    // ⚠️ WITHOUT THIS THE STUDENT LEARNS BY BEING REFUSED — or worse, is never
    // told the refusal lifted. Not one credit moved in either direction, so the
    // movement path cannot see this flip: the floor changed underneath a balance
    // that never budged.
    Event::assertDispatched(
        AccessRestored::class,
        fn (AccessRestored $event): bool => $event->balance->student_user_id === $this->student->getKey(),
    );

    Event::assertNotDispatched(AccessWithheld::class);
});

// The endpoint ----------------------------------------------------------------

it('opens and closes exam mode over the authenticated workspace only', function (): void {
    Sanctum::actingAs(examModeOperator());

    $this->postJson('/api/v1/manage/billing/exam-mode', [
        'starts_on' => CarbonImmutable::now()->toDateString(),
        'ends_on' => CarbonImmutable::now()->addDays(5)->toDateString(),
    ])->assertCreated();

    $this->getJson('/api/v1/manage/billing/exam-mode')
        ->assertOk()
        ->assertJsonPath('data.ends_on', CarbonImmutable::now()->addDays(5)->toDateString());

    $this->deleteJson('/api/v1/manage/billing/exam-mode')->assertOk();

    // Closed means covering nothing today — asserted through the reader the
    // booking path uses, not by counting rows.
    $this->getJson('/api/v1/manage/billing/exam-mode')->assertOk()->assertJsonPath('data', null);
});

it('closes every window covering today, not merely the first', function (): void {
    openWindow(0, 3);
    openWindow(-1, 9);

    app(ManageExamModeWindow::class)->handle($this->workspace, performedBy: $this->owner);

    // One row left behind would be exam mode still in force behind a screen
    // showing it as off — which is why the endpoint carries no uuid.
    expect(app(AccountStanding::class)->isWithheld($this->student, (int) $this->course->getKey()))->toBeFalse();
});

it('refuses a member without the exam-mode permission', function (): void {
    $member = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->setCurrentWorkspace($this->workspace, $member);

    Sanctum::actingAs($member);

    $this->postJson('/api/v1/manage/billing/exam-mode', [
        'starts_on' => CarbonImmutable::now()->toDateString(),
        'ends_on' => CarbonImmutable::now()->addDay()->toDateString(),
    ])->assertForbidden();

    $this->deleteJson('/api/v1/manage/billing/exam-mode')->assertForbidden();
});

it('refuses a window that ends before it starts', function (): void {
    Sanctum::actingAs(examModeOperator());

    $this->postJson('/api/v1/manage/billing/exam-mode', [
        'starts_on' => CarbonImmutable::now()->addDays(5)->toDateString(),
        'ends_on' => CarbonImmutable::now()->toDateString(),
    ])->assertStatus(422);
});

/*
| FR-032 · SC-010 — the number the refusal quotes is the number that unblocks.
|
| ⚠️ TWO ANSWERS TO ONE QUESTION, AND ONLY ONE OF THEM KNEW ABOUT EXAM MODE.
| `isWithheld` goes through WithholdingReader::stamp(), which reads the open
| window; `creditsNeededFor` recomputed the floor beside it and never passed the
| flag, so it defaulted to false. During a window the floor is forced to zero and
| the true requirement is the whole way back up from the debt — but the quote was
| computed against the ceiling exam mode had just suspended.
|
| What the student saw: "تحتاج 1 حصة"، buys exactly one, is refused again. The
| same number is printed by the booking refusal, the playback refusal and the
| withheld notification, so it was wrong in three places from one line.
*/
it('quotes what it will actually take to book, with the window open', function (): void {
    $standing = app(AccountStanding::class);
    $courseId = (int) $this->course->getKey();

    // Two sessions into their three-session ceiling.
    consumeCredits($this->balance, 2);

    openWindow();

    expect($standing->isWithheld($this->student, $courseId))->toBeTrue()
        // Zero floor + one session + two owed. NOT 1, which is what the
        // suspended ceiling of 3 would have answered.
        ->and($standing->creditsNeededFor($this->student, $courseId))->toBe(3);
});

it('quotes the smaller number the moment the window closes', function (): void {
    // The same balance, the same debt, the ceiling back in force — and the
    // requirement drops to one session. Derived, so nothing has to sweep.
    $standing = app(AccountStanding::class);
    $courseId = (int) $this->course->getKey();

    consumeCredits($this->balance, 2);

    expect($standing->isWithheld($this->student, $courseId))->toBeFalse()
        ->and($standing->creditsNeededFor($this->student, $courseId))->toBe(0);
});
