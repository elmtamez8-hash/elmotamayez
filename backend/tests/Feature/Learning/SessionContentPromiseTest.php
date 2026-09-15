<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ — THE SCREEN AND THE DOOR ANSWER ONE QUESTION.
|
| «محتوى هذه الحصة مقفول — افتحه بخصم حصة من رصيدك» is an INSTRUCTION, and until
| 2026-09-13 the curriculum printed it on every locked hour of the course —
| including hours belonging to a group the student was never in, which
| `POST /class-sessions/{uuid}/unlock` refuses with 403. A student in Saturday's
| group, enrolled and funded, was told to spend a credit on Sunday's lesson and
| refused one press later.
|
| ⛔ BOTH DIRECTIONS OR NEITHER. A file asserting only the refusal passes against
| a build that refuses everyone, and a file asserting only the promise passes
| against the bug it was written for. Every case here reads the curriculum AND
| presses the endpoint, and asserts they agree.
|
| ⚠️ AND THE COUNT IS ASSERTED TOO. `locked_session_count` drives «كذا حصّةً
| مقفولةً — افتحْها بكذا من رصيدِك», and it counts `no_seat` rows: an hour that
| cannot be bought must not be in it, or the number quotes a price for something
| not on sale.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());

    $this->mine = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // ⚠️ `course_id` AS WELL AS `cohort_id`. `hasOpenMembership()` reads the
    // course column, so a membership without it leaves the student outside every
    // group — and `cohort_gate.satisfied` then answers `false` for somebody this
    // file needs INSIDE a group. (No longer a LOCK: ٠٣٤ · FR-015 repealed
    // `no_cohort` and the tree opens regardless. The column is still required,
    // the reason is not what it was.)
    CohortMembership::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $this->mine->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
    ]);

    // Funded, so a refusal is never about the money.
    fundBooking($this->workspace, $this->student, $this->course, 3);
});

/** A delivered session in $cohort, carrying one published recording. */
function hourOf(object $test, Cohort $cohort): object
{
    $session = billableSession($test->workspace, $test->owner, $test->course, seatsTotal: 5);
    $session->forceFill(['cohort_id' => $cohort->getKey()])->save();

    Lesson::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'course_id' => $test->course->getKey(),
        'class_session_id' => $session->getKey(),
        'type' => LessonType::Video,
        'status' => ContentStatus::Published,
    ]);

    $session->refresh()->forceFill(['billable_seats' => 0])->save();

    return deliverBillableSession($session->refresh(), $test->owner, []);
}

/** What the two doors say about that hour, as the student. */
function bothDoors(object $test, object $session): array
{
    Sanctum::actingAs($test->student);
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    $tree = $test->getJson('/api/v1/courses/'.$test->course->uuid.'/curriculum');
    $tree->assertOk();

    $lock = null;

    foreach ($tree->json('sections') as $section) {
        foreach ($section['chapters'] ?? [] as $chapter) {
            foreach ($chapter['lessons'] ?? [] as $lesson) {
                if ($lesson['state'] === 'locked') {
                    $lock = $lesson['lock'];
                }
            }
        }
    }

    return [
        'lock' => $lock,
        'locked_session_count' => $tree->json('course.locked_session_count'),
        'door' => $test->postJson('/api/v1/class-sessions/'.$session->uuid.'/unlock'),
    ];
}

it('offers to sell the hour it can actually sell', function (): void {
    $seen = bothDoors($this, hourOf($this, $this->mine));

    expect($seen['lock']['code'])->toBe('no_seat')
        ->and($seen['lock']['message'])->toContain('افتحه بخصم حصة من رصيدك')
        ->and($seen['locked_session_count'])->toBe(1);

    // The sentence was an instruction, and the door obeys it.
    $seen['door']->assertOk();
});

it('promises nothing about an hour that belongs to another group', function (): void {
    $theirs = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
    ]);

    $seen = bothDoors($this, hourOf($this, $theirs));

    // ⛔ THE REGRESSION. This read `no_seat` + «افتحه بخصم حصة من رصيدك» while
    // the door answered 403 — an order and a refusal, one press apart.
    expect($seen['lock']['code'])->toBe('other_cohort')
        ->and($seen['lock']['message'])->not->toContain('رصيدك')
        // Not on sale, so not in the number that quotes a price.
        ->and($seen['locked_session_count'])->toBe(0);

    $seen['door']->assertForbidden();
});

it('keeps the promise for an hour the student was moved away from', function (): void {
    $old = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
    ]);

    $session = hourOf($this, $old);

    /*
    | They sat in it, then moved on. «Was ever a member», never «is one today».
    |
    | ⚠️ WRITTEN WITH `DB::table`, AND THAT IS THE PRODUCTION SHAPE RATHER THAN A
    | SHORTCUT. `closed_slot` is deliberately NOT fillable — it is claimed inside
    | the conditional UPDATE that closes a membership — and the unique key is
    | `(student, course, closed_slot)` with `0` meaning open, so a factory row
    | would collide with the student's CURRENT group instead of sitting beside
    | it. `uuid` and the timestamps are passed explicitly because the model layer
    | is not here to supply them.
    */
    // Rebuilt in the order production writes it: they joined the old group,
    // then were moved. `unique(student, course, closed_slot)` with `0` meaning
    // open is what forbids any other order — two open rows collide, and so do
    // two rows inserted closed.
    CohortMembership::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())->delete();

    $first = DB::table('cohort_memberships')->insertGetId([
        'workspace_id' => $this->workspace->getKey(),
        'uuid' => (string) Str::uuid(),
        'cohort_id' => $old->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // ⚠️ `closed_slot` IS CLAIMED INSIDE THE STATEMENT THAT CLOSES THE ROW, and
    // it is deliberately not fillable — so the close is written the way
    // `CohortMembershipWriter` writes it rather than through the model.
    DB::table('cohort_memberships')
        ->where('id', $first)
        ->update(['closed_at' => now(), 'closed_slot' => $first]);

    CohortMembership::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $this->mine->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
    ]);

    $seen = bothDoors($this, $session);

    expect($seen['lock']['code'])->toBe('no_seat')
        ->and($seen['locked_session_count'])->toBe(1);

    $seen['door']->assertOk();
});

/*
| ⛔ ٠٢٦ — AND THE THIRD CASE IS AN HOUR THAT WAS NEVER GIVEN AT ALL.
|
| `SessionCompleted` is not `SessionDelivered`, and the recording ingest hangs
| off the FIRST (`LiveSessionsServiceProvider:228`) — so a session whose teacher
| never turned up still produces a published lesson in the tree. Until today its
| row read `no_seat` + «افتحه بخصم حصة من رصيدك» to a student sitting in that
| very group, while this endpoint answered 403: `unlockableSessionIds()` never
| asked about delivery and `unlockOfferFor()` always did.
|
| The row is REMOVED rather than re-worded, because every sentence available was
| false — «ليست من حصص مجموعتك» to somebody who is in it, or an offer that is
| refused. Nothing was given, so nothing is owed and nothing is on sale.
*/

/** The same hour, ended and never delivered — the teacher did not turn up. */
function unheldHourOf(object $test, Cohort $cohort): object
{
    $session = billableSession($test->workspace, $test->owner, $test->course, seatsTotal: 5);

    $session->forceFill([
        'cohort_id' => $cohort->getKey(),
        // Ended, and NOT delivered. This is the exact pair the ingest leaves
        // behind when nobody taught the hour — never forced on a delivered one.
        'status' => ClassSessionStatus::Completed->value,
        'delivered_at' => null,
    ])->save();

    Lesson::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'course_id' => $test->course->getKey(),
        'class_session_id' => $session->getKey(),
        'type' => LessonType::Video,
        'status' => ContentStatus::Published,
    ]);

    return $session->refresh();
}

it('says nothing at all about an hour that was never given', function (): void {
    $seen = bothDoors($this, unheldHourOf($this, $this->mine));

    // The row is gone: no lock to read, and nothing quoting a price for it.
    expect($seen['lock'])->toBeNull()
        ->and($seen['locked_session_count'])->toBe(0);

    // And the door agrees — which is the whole contract of this file.
    $seen['door']->assertForbidden();
});

it('shows that same hour the moment it is actually delivered', function (): void {
    /*
    | ⚠️ THE CONTROL, AND WITHOUT IT THE CASE ABOVE PASSES AGAINST A BUILD THAT
    | HIDES EVERY RECORDING. It is the same session, same group, same student —
    | the only thing that changes is that the hour was taught.
    */
    $session = hourOf($this, $this->mine);

    $seen = bothDoors($this, $session);

    expect($seen['lock']['code'])->toBe('no_seat')
        ->and($seen['locked_session_count'])->toBe(1);

    $seen['door']->assertOk();
});
