<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\ScheduleClassSession;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Models\Plan;
use Carbon\CarbonImmutable;

/*
| ٠٣٦ · T037 — THE GATE TAKES NOTHING FROM ANYBODY WHO ALREADY HAS IT.
|
| ⛔ THE PRECEDENT IS LIVE AND IS WRITTEN DOWN IN THIS REPOSITORY. Creating the
| first group of «Laravel Mastery» shut the curriculum RETROACTIVELY on four
| enrolments that had signed up weeks earlier — one of them at 100%, with every
| lesson completed. A condition that repossesses what somebody already bought is
| the shape ٠٣٤ · FR-015 was written to repeal, and a new gate must not reach the
| same outcome by another road.
|
| ⚠️ THE POSITIVE CONTROL IS A STUDENT WHO IS **NOT** A MEMBER, and that is the
| load-bearing detail. A member keeps seeing their own group through a separate
| membership query that never passes through the gate — so measuring «did the
| group disappear» with the member is green against a build that disabled the
| gate entirely.
|
| ⚠️ AND «THE CONTENT IS STILL OPEN» IS DELIBERATELY NOT MEASURED. ٠٣٤ · FR-015
| removed the group condition from the curriculum gate altogether — `LessonGate`
| no longer reads groups at all — so nothing in the tree is capable of closing
| it, and a case asserting it open would pass for a reason that has nothing to do
| with this shipment.
*/
beforeEach(function (): void {
    // A `->delay()` runs immediately on `sync`, so the timeline jobs would close
    // the session inside its own scheduling.
    fakeSessionTimeline();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
    ]);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill([
        'status' => 'published',
        'teacher_profile_id' => $this->profile->getKey(),
    ])->save();

    $this->cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->owner->getKey(),
        'status' => Cohort::OPEN,
        'name' => 'مجموعة السبت',
    ]);

    // The only price on the platform for this group. Switching it off is the
    // whole event this file is about.
    $this->plan = groupPriceFor($this->course);

    $this->member = gateFixtureStudent('عضو');
    $this->stranger = gateFixtureStudent('غريب');

    app(JoinCohort::class)->handle($this->cohort, $this->member);

    // Scheduling seats every member of the group, which is how the member comes
    // to hold a chair before the price goes away.
    app(ScheduleClassSession::class)->handle(new ScheduleSessionData(
        teacherProfileId: (int) $this->profile->getKey(),
        title: 'درس المجموعة',
        type: ClassSessionType::Group,
        startsAt: CarbonImmutable::parse('+3 days'),
        durationMinutes: 60,
        seatsTotal: 8,
        courseId: (int) $this->course->getKey(),
        cohortId: (int) $this->cohort->getKey(),
    ), $this->owner);
});

/**
 * An enrolled student who pays by credit and holds no subscription.
 *
 * ⚠️ NAMED FOR THIS FILE. A Pest helper is a GLOBAL function, and
 * `StudentWithoutWorkspaceTest` already declares `enrolledStudent()` with a
 * different signature — invisible while each file gets its own process, and a
 * fatal redeclaration the moment one worker loads both, which is every
 * `pest --parallel` run.
 */
function gateFixtureStudent(string $name): User
{
    $test = test();

    // `last_workspace_id` left null: a self-registered student is a member of no
    // workspace, and a fixture that stamps it measures somebody else.
    $student = User::factory()->create(['first_name' => $name, 'last_workspace_id' => null]);

    Enrollment::query()->create([
        'workspace_id' => $test->workspace->getKey(),
        'course_id' => $test->course->getKey(),
        'student_user_id' => $student->getKey(),
        'source' => 'manual',
        'status' => EnrollmentStatus::Active,
        'enrolled_at' => now(),
    ]);

    fundBooking($test->workspace, $student, $test->course);

    return $student;
}

function gateBookedSeats(User $student): int
{
    return SessionBooking::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $student->getKey())
        ->where('status', BookingStatus::Booked)
        ->count();
}

it('leaves a member their membership and their seat when the last price goes away', function (): void {
    expect(gateBookedSeats($this->member))->toBe(1);

    // The teacher switches off the only plan that reached this group.
    $this->plan->forceFill(['is_active' => false])->save();

    $membership = CohortMembership::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $this->member->getKey())
        ->whereNull('closed_at')
        ->first();

    expect($membership)->not->toBeNull()
        ->and((int) $membership->cohort_id)->toBe((int) $this->cohort->getKey())
        // ⛔ AND THE CHAIR IS STILL THEIRS. Releasing it would repossess a lesson
        // the student has already been seated for, over a price they never paid
        // with and cannot influence.
        ->and(gateBookedSeats($this->member))->toBe(1);
});

it('still shows the member their own group after it stops being offered', function (): void {
    $this->plan->forceFill(['is_active' => false])->save();

    $payload = $this->actingAs($this->member, 'sanctum')
        ->getJson('/api/v1/courses/'.$this->course->uuid.'/cohorts')
        ->assertOk()
        ->json();

    expect($payload['membership']['cohort_name'])->toBe('مجموعة السبت')
        // And it IS out of the offer — the two facts are separate, and a build
        // that simply stopped filtering would satisfy the line above.
        ->and($payload['cohorts'])->toBe([]);
});

it('closes the group to a student who is not in it', function (): void {
    /*
    | ⛔ THE POSITIVE CONTROL. Everything above is about somebody who already
    | holds what the gate is about — so without this case the file is equally
    | green against a build where the gate does nothing at all.
    */
    $this->plan->forceFill(['is_active' => false])->save();

    $this->actingAs($this->stranger, 'sanctum')
        ->postJson('/api/v1/cohorts/'.$this->cohort->uuid.'/join')
        ->assertStatus(422)
        ->assertJsonPath('code', 'cohort_not_listed');

    expect(CohortMembership::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $this->stranger->getKey())
        ->count())->toBe(0);
});
