<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\Payments\Models\SessionUnlock;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · T081 · FR-013ب — BUYING AN HOUR YOU NEVER HELD A SEAT IN.
|
| A student enrolled in the course, whose group the lesson belonged to, may pay a
| credit for its material even though they never booked it. That is the whole
| right, and it is easy to write a test that measures something else entirely.
|
| ⛔ THREE CONDITIONS STAND IN FRONT OF IT, AND EACH ONE ANSWERS FIRST:
|
|  · THE HOUR WAS DELIVERED. Undelivered, `unlockOfferFor()` returns null and the
|    endpoint refuses — correctly, and about a different rule.
|  · THE STUDENT IS FUNDED. Unfunded the answer is 422 rather than 200, and a
|    file asserting «refused» would pass over a build that refuses everyone.
|  · THE GROUP CAN SEE THE SESSION. Enrolment alone is not the predicate: a
|    session belongs to a cohort, and a student who was never in it is refused.
|
| ⚠️ AND THE TRANSFERRED STUDENT IS ASSERTED ON THEIR OLD GROUP'S SESSION, which
| is the case the predicate is written for: «was ever a member», never «is a
| member today». Asked the other way, a student moved between groups is refused
| the hours they sat in — the same defect `ConversationPolicy` already paid for.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());

    $this->cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
    ]);

    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 5);
    $this->session->forceFill(['cohort_id' => $this->cohort->getKey()])->save();

    // (١) There is material to buy. With none, the offer is null and the refusal
    // is «nothing to sell» rather than anything about a seat.
    Lesson::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'class_session_id' => $this->session->getKey(),
        'type' => LessonType::Video,
        'status' => ContentStatus::Published,
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // (٢) Funded. Unfunded the answer is 422, which is a different assertion.
    fundBooking($this->workspace, $this->student, $this->course, 3);

    // (٣) Delivered, with nobody in the room and no seats frozen — so no seat of
    // this student's exists anywhere in the fixture.
    $this->session->refresh()->forceFill(['billable_seats' => 0])->save();
    $this->session = deliverBillableSession($this->session->refresh(), $this->owner, []);
});

/** Put the student in a group, then ask for the hour as themselves. */
function unlockAsOutsider(object $test, ?Cohort $joins, ?Cohort $movesTo = null): object
{
    if ($joins !== null) {
        CohortMembership::factory()->create([
            'workspace_id' => $test->workspace->getKey(),
            'cohort_id' => $joins->getKey(),
            'student_user_id' => $test->student->getKey(),
        ]);
    }

    if ($movesTo !== null) {
        CohortMembership::query()->withoutWorkspaceScope()
            ->where('student_user_id', $test->student->getKey())
            ->where('cohort_id', $joins?->getKey())
            ->update(['closed_at' => now()]);

        CohortMembership::factory()->create([
            'workspace_id' => $test->workspace->getKey(),
            'cohort_id' => $movesTo->getKey(),
            'student_user_id' => $test->student->getKey(),
        ]);
    }

    Sanctum::actingAs($test->student);
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    return $test->postJson('/api/v1/class-sessions/'.$test->session->uuid.'/unlock');
}

it('sells the hour to an enrolled student who never booked a seat in it', function (): void {
    unlockAsOutsider($this, $this->cohort)->assertOk();

    $unlock = SessionUnlock::query()
        ->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->sole();

    expect($unlock->reason)->toBe(SessionUnlock::REASON_CONSENT)
        ->and((int) $unlock->credits_charged)->toBe(1)
        ->and((int) billingBalance($this->workspace, $this->student, $this->course)->remaining_credits)->toBe(2);
});

it('still sells it after the student has been moved to another group', function (): void {
    $newGroup = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
    ]);

    // ⛔ ASSERTED ON THE OLD GROUP'S SESSION, which is the only place the rule
    // differs from «is a member today». Their current group never held this hour.
    unlockAsOutsider($this, $this->cohort, $newGroup)->assertOk();

    expect(SessionUnlock::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->exists())->toBeTrue();
});

it('refuses a student whose group never held the hour', function (): void {
    $strangersGroup = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
    ]);

    // The control for condition (٣). Enrolled, funded, and the hour delivered —
    // everything the case above had except the one fact being measured.
    unlockAsOutsider($this, $strangersGroup)->assertForbidden();

    expect(SessionUnlock::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and((int) billingBalance($this->workspace, $this->student, $this->course)->remaining_credits)->toBe(3);
});
