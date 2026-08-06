<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;

/*
| SC-001 — zero cases where bookings exceed seats.
|
| The guard is one conditional UPDATE (`WHERE seats_taken < seats_total`), not a
| row lock: lockForUpdate() is a no-op on SQLite, so a test written around it
| would pass here and prove nothing about MySQL. Two sequential calls on the last
| seat travel exactly the path concurrent ones do — the second finds the
| condition false and is refused.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create(['created_by' => $this->owner->getKey()]);
});

/** A student enrolled with this teacher, so eligibility is not what refuses them. */
function eligibleStudent(): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);

    return $student;
}

it('refuses the second claim on the last seat', function (): void {
    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'seats_total' => 1,
    ]);

    $first = eligibleStudent();
    $second = eligibleStudent();

    app(BookSeat::class)->handle($session, $first);

    expect(fn () => app(BookSeat::class)->handle($session->refresh(), $second))
        ->toThrow(DomainException::class);

    expect($session->refresh()->seats_taken)->toBe(1);
});

it('never lets seats_taken pass seats_total under repeated claims', function (): void {
    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'seats_total' => 3,
    ]);

    $accepted = 0;

    foreach (range(1, 6) as $ignored) {
        try {
            app(BookSeat::class)->handle($session->refresh(), eligibleStudent());
            $accepted++;
        } catch (DomainException) {
            // Expected once the session fills.
        }
    }

    expect($accepted)->toBe(3)
        ->and($session->refresh()->seats_taken)->toBe(3);
});

// A double-tap on the button must not swallow a seat nobody sits in.
it('gives the seat back when the same student books twice', function (): void {
    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'seats_total' => 5,
    ]);

    $student = eligibleStudent();

    app(BookSeat::class)->handle($session, $student);

    expect(fn () => app(BookSeat::class)->handle($session->refresh(), $student))
        ->toThrow(DomainException::class);

    expect($session->refresh()->seats_taken)->toBe(1);
});

it('refuses a student with no enrolment with this teacher', function (): void {
    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'seats_total' => 5,
    ]);

    $outsider = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    expect(fn () => app(BookSeat::class)->handle($session, $outsider))
        ->toThrow(DomainException::class);

    expect($session->refresh()->seats_taken)->toBe(0);
});
