<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Community\Jobs\BuildReportCardsJob;
use App\Modules\Community\Jobs\RenderReportCardJob;
use App\Modules\Community\Models\GradingScheme;
use App\Modules\Community\Models\PeriodicReview;
use App\Modules\Community\Models\ReportCard;
use App\Modules\Community\Models\ReportCardSegment;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\GuardianPermission;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| `SC-012` and `SC-018` — the card's numbers match the source with ZERO
| difference, and the final grade matches the same sum done by hand.
|
| ⚠️ TWO WORKSPACES AND TWO TEACHERS, ALWAYS. A card with one segment looks
| perfectly correct on a single-workspace installation, and every isolation
| defect this phase can produce — a segment carrying the other teacher's grades,
| a total averaging across teachers who should be separate, a workspace scope
| doing nothing because the job runs outside every workspace — is invisible with
| one. That is why `plan.md` §ق-٤ refuses to let a teacher generate the card at
| all.
|
| ⚠️ AND THE FIXTURE CARRIES A PRACTICE ATTEMPT (FR-038) AND A COMPONENT WITH NO
| DATA (FR-053). Both are requirements a naive implementation satisfies by
| accident on data that contains neither, and both would then ship.
*/

const PERIOD_START = '2026-08-01';
const PERIOD_END = '2026-08-31';

beforeEach(function (): void {
    // A card is written by a platform job and rendered on a queue. The render is
    // faked so no PDF is produced for eighty fixtures; the BUILD runs for real.
    Queue::fake([RenderReportCardJob::class]);

    [$this->workspaceA, $this->teacherA] = $this->createWorkspaceWithOwner();
    [$this->workspaceB, $this->teacherB] = $this->createWorkspaceWithOwner();

    $this->student = User::factory()->create();

    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    $this->courseA = Course::factory()->create(['workspace_id' => $this->workspaceA->getKey()]);
    $this->createEnrollment($this->workspaceA, $this->courseA, $this->student);

    $this->setCurrentWorkspace($this->workspaceB, $this->teacherB);
    $this->courseB = Course::factory()->create(['workspace_id' => $this->workspaceB->getKey()]);
    $this->createEnrollment($this->workspaceB, $this->courseB, $this->student);
});

function buildCards(): void
{
    app()->call([new BuildReportCardsJob(PERIOD_START, PERIOD_END), 'handle']);
}

it('matches the source data with zero difference, across two teachers', function (): void {
    /*
    | Teacher A weights exams 50 / attendance 50. The student scores 80/100 on a
    | real exam and 10/100 on a PRACTICE run, and attends one of two sessions.
    |
    | By hand: exams 80%, attendance 50%, grade = 80×0.5 + 50×0.5 = 65.
    | With the practice attempt counted, exams would be 45% and the grade 47.5 —
    | so the two numbers cannot be confused for one another.
    */
    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    GradingScheme::factory()->create([
        'period_start' => PERIOD_START,
        'period_end' => PERIOD_END,
        'weights' => ['exams' => 50, 'homework' => 0, 'attendance' => 50, 'participation' => 0],
    ]);

    periodAttempt($this->workspaceA, $this->student, 80, 100);
    periodAttempt($this->workspaceA, $this->student, 10, 100, practice: true);
    periodSession($this->workspaceA, $this->courseA, $this->student, AttendanceStatus::Present);
    periodSession($this->workspaceA, $this->courseA, $this->student, AttendanceStatus::Absent);

    /*
    | Teacher B weights exams 100. The student scores 40/50 — 80%. Both teachers
    | land on 80% for exams DELIBERATELY: if the job crossed the two workspaces,
    | the exam figure would still look right, and only the grade would move.
    */
    $this->setCurrentWorkspace($this->workspaceB, $this->teacherB);
    GradingScheme::factory()->create([
        'period_start' => PERIOD_START,
        'period_end' => PERIOD_END,
        'weights' => ['exams' => 100, 'homework' => 0, 'attendance' => 0, 'participation' => 0],
    ]);

    periodAttempt($this->workspaceB, $this->student, 40, 50);

    buildCards();

    $card = ReportCard::query()->where('student_user_id', $this->student->getKey())->first();

    expect($card)->not->toBeNull();
    expect($card->segments()->count())->toBe(2);

    $a = ReportCardSegment::withoutGlobalScopes()
        ->where('report_card_id', $card->getKey())
        ->where('workspace_id', $this->workspaceA->getKey())
        ->first();

    $b = ReportCardSegment::withoutGlobalScopes()
        ->where('report_card_id', $card->getKey())
        ->where('workspace_id', $this->workspaceB->getKey())
        ->first();

    expect((float) $a->components['exams']['pct'])->toBe(80.0);
    expect($a->attendance_pct)->toBe(50.0);
    expect($a->segment_pct)->toBe(65.0);

    expect((float) $b->components['exams']['pct'])->toBe(80.0);
    expect($b->segment_pct)->toBe(80.0);

    // The card's overall is the flat mean of the two teachers' grades.
    expect($card->overall_pct)->toBe(72.5);
});

it('leaves a practice attempt out of the official grade', function (): void {
    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    GradingScheme::factory()->create([
        'period_start' => PERIOD_START,
        'period_end' => PERIOD_END,
        'weights' => ['exams' => 100, 'homework' => 0, 'attendance' => 0, 'participation' => 0],
    ]);

    periodAttempt($this->workspaceA, $this->student, 90, 100);
    periodAttempt($this->workspaceA, $this->student, 0, 100, practice: true);

    buildCards();

    $segment = ReportCardSegment::withoutGlobalScopes()
        ->where('workspace_id', $this->workspaceA->getKey())
        ->first();

    // 90, not 45. A practice run is where a student is supposed to get things
    // wrong; grading it makes the safest strategy never to practise.
    expect((float) $segment->components['exams']['pct'])->toBe(90.0);
});

/*
| `FR-053` — a component with no data is EXCLUDED and the rest re-weighted, never
| counted as zero. A student who was set no homework is not a student who scored
| nothing on it, and the difference here is 27 points of their grade.
*/
it('excludes a component with no data and re-weights the rest', function (): void {
    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    GradingScheme::factory()->create([
        'period_start' => PERIOD_START,
        'period_end' => PERIOD_END,
        // Homework is weighted 30 and no homework was ever set.
        'weights' => ['exams' => 50, 'homework' => 30, 'attendance' => 20, 'participation' => 0],
    ]);

    periodAttempt($this->workspaceA, $this->student, 90, 100);
    periodSession($this->workspaceA, $this->courseA, $this->student, AttendanceStatus::Present);

    buildCards();

    $segment = ReportCardSegment::withoutGlobalScopes()
        ->where('workspace_id', $this->workspaceA->getKey())
        ->first();

    // Homework is absent from the components entirely, not present with a zero.
    expect($segment->components)->not->toHaveKey('homework');

    // 50 and 20 re-weight to 71.43 and 28.57 — and the shown weights add to 100,
    // so a parent can check the arithmetic printed beside the grade.
    expect(round((float) $segment->components['exams']['weight'] + (float) $segment->components['attendance']['weight']))
        ->toBe(100.0);

    // By hand: (90×50 + 100×20) / 70 = 92.86. Counted as zero it would be 65 —
    // twenty-eight points lower, for a reason nobody could see on the page.
    expect($segment->segment_pct)->toBe(92.86);
});

it('counts graded homework and leaves an unmarked submission alone', function (): void {
    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    GradingScheme::factory()->create([
        'period_start' => PERIOD_START,
        'period_end' => PERIOD_END,
        'weights' => ['exams' => 0, 'homework' => 100, 'attendance' => 0, 'participation' => 0],
    ]);

    $marked = Assignment::factory()->published()->create([
        'workspace_id' => $this->workspaceA->getKey(),
        'course_id' => $this->courseA->getKey(),
        'created_by' => $this->teacherA->getKey(),
        'points' => 20,
    ]);

    $unmarked = Assignment::factory()->published()->create([
        'workspace_id' => $this->workspaceA->getKey(),
        'course_id' => $this->courseA->getKey(),
        'created_by' => $this->teacherA->getKey(),
        'points' => 80,
    ]);

    Submission::create([
        'workspace_id' => $this->workspaceA->getKey(),
        'assignment_id' => $marked->getKey(),
        'student_user_id' => $this->student->getKey(),
        'state' => Submission::STATE_ON_TIME,
        'submitted_at' => '2026-08-14 09:00:00',
        'score' => 15,
        'graded_at' => '2026-08-15 09:00:00',
        'graded_by' => $this->teacherA->getKey(),
    ]);

    Submission::create([
        'workspace_id' => $this->workspaceA->getKey(),
        'assignment_id' => $unmarked->getKey(),
        'student_user_id' => $this->student->getKey(),
        'state' => Submission::STATE_ON_TIME,
        'submitted_at' => '2026-08-14 09:00:00',
    ]);

    buildCards();

    $segment = ReportCardSegment::withoutGlobalScopes()
        ->where('workspace_id', $this->workspaceA->getKey())
        ->first();

    // 15/20 = 75. If the unmarked essay counted as zero it would be 15/100 = 15:
    // the student's grade falling because the TEACHER has not marked it yet.
    expect((float) $segment->components['homework']['pct'])->toBe(75.0);
});

it('grades participation from a published assessment and ignores a draft', function (): void {
    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    GradingScheme::factory()->create([
        'period_start' => PERIOD_START,
        'period_end' => PERIOD_END,
        'weights' => ['exams' => 0, 'homework' => 0, 'attendance' => 0, 'participation' => 100],
    ]);

    // A draft: written but never published, so the teacher has not stood behind
    // it and it grades nobody.
    PeriodicReview::factory()->create([
        'student_user_id' => $this->student->getKey(),
        'teacher_user_id' => $this->teacherA->getKey(),
        'period_start' => PERIOD_START,
        'period_end' => PERIOD_END,
        'participation' => 1,
    ]);

    buildCards();

    expect(ReportCardSegment::withoutGlobalScopes()->count())->toBe(0);

    ReportCard::query()->delete();

    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    PeriodicReview::query()->withoutGlobalScopes()->delete();

    PeriodicReview::factory()->published()->create([
        'student_user_id' => $this->student->getKey(),
        'teacher_user_id' => $this->teacherA->getKey(),
        'period_start' => PERIOD_START,
        'period_end' => PERIOD_END,
        'participation' => 4,
    ]);

    buildCards();

    $segment = ReportCardSegment::withoutGlobalScopes()
        ->where('workspace_id', $this->workspaceA->getKey())
        ->first();

    // 4 out of 5 is 80%.
    expect((float) $segment->components['participation']['pct'])->toBe(80.0);
});

it('shows each teacher their own segment and never the other one', function (): void {
    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    periodAttempt($this->workspaceA, $this->student, 80, 100);

    $this->setCurrentWorkspace($this->workspaceB, $this->teacherB);
    periodAttempt($this->workspaceB, $this->student, 30, 100);

    buildCards();

    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    Sanctum::actingAs($this->teacherA);

    $this->getJson('/api/v1/manage/report-card-segments')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.components.exams.pct', 80);
});

it('shows the student one card carrying both teachers', function (): void {
    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    periodAttempt($this->workspaceA, $this->student, 80, 100);

    $this->setCurrentWorkspace($this->workspaceB, $this->teacherB);
    periodAttempt($this->workspaceB, $this->student, 60, 100);

    buildCards();

    Sanctum::actingAs($this->student);

    $uuid = $this->getJson('/api/v1/report-cards')
        ->assertOk()
        ->assertJsonCount(1)
        ->json('0.uuid');

    $this->getJson("/api/v1/report-cards/{$uuid}")
        ->assertOk()
        ->assertJsonCount(2, 'segments')
        // The teacher's NAME, not merely the key — `users` has no `name` column,
        // so an eager load naming it yields an empty string on every screen.
        ->assertJsonPath('segments.0.teacher_name', $this->teacherA->name);
});

it('refuses a classmate the card', function (): void {
    $classmate = $this->addWorkspaceMember($this->workspaceA, Roles::STUDENT);
    $this->createEnrollment($this->workspaceA, $this->courseA, $classmate);

    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    periodAttempt($this->workspaceA, $this->student, 80, 100);

    buildCards();

    $card = ReportCard::query()->where('student_user_id', $this->student->getKey())->first();

    Sanctum::actingAs($classmate);

    // 403 rather than 404: their own card exists too, so "no such card" would be
    // a lie that also tells them the uuid was real.
    $this->getJson("/api/v1/report-cards/{$card->uuid}")->assertForbidden();
});

/*
| ⚠️ THIS IS THE CASE THAT MEASURES `ReportCard::segments()`'s SCOPE BYPASS, and
| the obvious guardian test does not. A guardian who teaches nowhere resolves to
| no workspace, so `WorkspaceScope` adds no condition and the card comes back
| whole whether the bypass is there or not — every assertion green, guarding
| nothing. A guardian who is ALSO a teacher has a `last_workspace_id`, the scope
| resolves, and their child's card arrives carrying their own segment and none of
| their colleagues'.
|
| Delete the `withoutGlobalScopes()` on that relation and this test fails; delete
| it and run any other test in this file and they all still pass.
*/
it('shows a guardian who is also a teacher the whole card, not their own slice', function (): void {
    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    periodAttempt($this->workspaceA, $this->student, 80, 100);

    $this->setCurrentWorkspace($this->workspaceB, $this->teacherB);
    periodAttempt($this->workspaceB, $this->student, 60, 100);

    buildCards();

    $guardian = guardianOf($this->student, [GuardianPermission::Results]);

    // The guardian teaches in workspace A: a parent who also tutors is ordinary,
    // and it is the only shape in which the reader resolves to a workspace.
    $this->workspaceA->members()->attach($guardian->getKey());
    $guardian->forceFill(['last_workspace_id' => $this->workspaceA->getKey()])->save();

    $card = ReportCard::query()->where('student_user_id', $this->student->getKey())->firstOrFail();

    Sanctum::actingAs($guardian);

    $this->getJson("/api/v1/report-cards/{$card->uuid}")
        ->assertOk()
        ->assertJsonCount(2, 'segments');
});

it('refuses a guardian entitled only to attendance', function (): void {
    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    periodAttempt($this->workspaceA, $this->student, 80, 100);

    buildCards();

    $card = ReportCard::query()->where('student_user_id', $this->student->getKey())->firstOrFail();

    // The permission is the point, not the relation. A guardian entitled to
    // attendance news has no business reading a term's grades — and 403, because
    // an empty answer would say «no card exists» about a child who has one.
    Sanctum::actingAs(guardianOf($this->student, [GuardianPermission::Attendance]));

    $this->getJson("/api/v1/report-cards/{$card->uuid}")->assertForbidden();
});
