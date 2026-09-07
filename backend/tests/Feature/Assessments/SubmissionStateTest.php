<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GradeSubmission;
use App\Modules\Assessments\Actions\GrantExtension;
use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Assessments\Support\LatePenalty;
use App\Modules\Courses\Models\Course;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| SC-016 · FR-045 · FR-046أ · FR-047. The three states, the extension, and the
| mark that can never go below zero.
*/

it('stamps on time, late with its size, and refuses a rejected late hand-in', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $submit = app(SubmitAssignment::class);

    [$open] = courseAssignment($workspace, $owner, $student, ['due_at' => now()->addDay()]);
    $onTime = $submit->handle($open, $student, 'في الموعد.');

    expect($onTime->state)->toBe(Submission::STATE_ON_TIME)
        ->and($onTime->late_by_minutes)->toBe(0);

    [$overdue] = courseAssignment($workspace, $owner, $student, ['due_at' => now()->subHours(30)]);
    $late = $submit->handle($overdue, $student, 'متأخر.');

    expect($late->state)->toBe(Submission::STATE_LATE)
        // Half past a day, in minutes — the size is recorded, not just the fact.
        ->and($late->late_by_minutes)->toBeGreaterThanOrEqual(1800);

    [$closed] = courseAssignment($workspace, $owner, $student, [
        'due_at' => now()->subDay(),
        'late_policy' => Assignment::LATE_REJECT,
    ]);

    expect(fn () => $submit->handle($closed, $student, 'متأخر جداً.'))
        ->toThrow(DomainException::class);
});

it('treats a hand-in at the deadline itself as on time', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);

    $moment = CarbonImmutable::parse('2026-09-01 12:00:00');
    [$assignment] = courseAssignment($workspace, $owner, $student, ['due_at' => $moment]);

    /*
    | ⚠️ THE BOUNDARY, AND IT IS THE ONE NOBODY REPORTS. A student who hands in
    | with a second to spare and is marked late assumes they misread the clock;
    | `>` rather than `>=` in the comparison is the whole of it.
    */
    CarbonImmutable::setTestNow($moment);

    $submission = app(SubmitAssignment::class)->handle($assignment, $student, 'بالضبط.');

    CarbonImmutable::setTestNow();

    expect($submission->state)->toBe(Submission::STATE_ON_TIME)
        ->and($submission->late_by_minutes)->toBe(0);
});

it('does not call a hand-in inside an extension late', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$assignment] = courseAssignment($workspace, $owner, $student, ['due_at' => now()->subDays(2)]);

    app(GrantExtension::class)->handle($assignment, $owner, $student, now()->addDay());

    $submission = app(SubmitAssignment::class)->handle($assignment, $student, 'داخل المهلة.');

    // FR-047: inside the extension the state is on time, not late-but-forgiven.
    expect($submission->state)->toBe(Submission::STATE_ON_TIME)
        ->and($submission->late_by_minutes)->toBe(0);
});

it('caps the penalty and never produces a negative mark', function (): void {
    $penalties = app(LatePenalty::class);

    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);

    /*
    | ⚠️ TEN DAYS AT 20٪ IS −100٪ WITHOUT THE CAP — a submission worth MINUS its
    | own marks, which then drags down every total it is summed into. The cap is
    | the requirement; the floor at zero is the second, independent guard, and
    | the pair is why a cap of 100٪ produces a clean zero rather than a rounding
    | artefact below it (FR-046أ · SC-016).
    */
    $capped = Assignment::factory()->published()->penalised(perDay: 20, cap: 60)->create([
        'workspace_id' => $workspace->getKey(),
        'points' => 10,
        'created_by' => $owner->getKey(),
    ]);

    expect($penalties->percentFor($capped, 10 * 1440))->toBe(60.0)
        ->and($penalties->apply(10.0, 60.0))->toBe(4.0);

    $total = Assignment::factory()->published()->penalised(perDay: 50, cap: 100)->create([
        'workspace_id' => $workspace->getKey(),
        'points' => 10,
        'created_by' => $owner->getKey(),
    ]);

    expect($penalties->percentFor($total, 5 * 1440))->toBe(100.0)
        ->and($penalties->apply(10.0, 100.0))->toBe(0.0)
        // A day BEGUN counts whole (Q6): one minute past is one day.
        ->and($penalties->percentFor($total, 1))->toBe(50.0)
        ->and($penalties->percentFor($total, 0))->toBe(0.0);

    // And through the Action, on a real row, since the arithmetic being right in
    // isolation is not the same as it reaching the column.
    [$assignment] = courseAssignment($workspace, $owner, $student, [
        'due_at' => now()->subDays(3),
        'points' => 10,
        'late_policy' => Assignment::LATE_PENALTY,
        'late_penalty_pct_per_day' => 50,
        'late_penalty_cap_pct' => 100,
    ]);

    $submission = app(SubmitAssignment::class)->handle($assignment, $student, 'متأخر ثلاثة أيام.');
    $graded = app(GradeSubmission::class)->handle($submission, $owner, 10.0);

    expect((float) $graded->score)->toBe(0.0)
        ->and((float) $graded->late_penalty_applied_pct)->toBe(100.0);
});

it('freezes the penalty at grading rather than deriving it afterwards', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$assignment] = courseAssignment($workspace, $owner, $student, [
        'due_at' => now()->subDays(2),
        'points' => 10,
        'late_policy' => Assignment::LATE_PENALTY,
        'late_penalty_pct_per_day' => 25,
        'late_penalty_cap_pct' => 100,
    ]);

    $submission = app(SubmitAssignment::class)->handle($assignment, $student, 'متأخر.');
    $graded = app(GradeSubmission::class)->handle($submission, $owner, 10.0);

    expect((float) $graded->late_penalty_applied_pct)->toBe(50.0);

    // The teacher softens the policy after the fact — an ordinary end-of-term
    // act, and the one that would silently re-price everything already marked.
    $assignment->update(['late_penalty_pct_per_day' => 0]);

    expect((float) $graded->refresh()->late_penalty_applied_pct)->toBe(50.0)
        ->and((float) $graded->score)->toBe(5.0);
});

it('refuses to replace work that has already been marked', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$assignment] = courseAssignment($workspace, $owner, $student, ['due_at' => now()->addDay(), 'points' => 10]);

    $submission = app(SubmitAssignment::class)->handle($assignment, $student, 'النسخة الأولى.');

    // Before marking, a correction is welcome.
    $again = app(SubmitAssignment::class)->handle($assignment, $student, 'النسخة الثانية.');
    expect($again->answer_text)->toBe('النسخة الثانية.');

    app(GradeSubmission::class)->handle($again, $owner, 8.0);

    // ⚠️ AFTER marking it is rewriting the evidence behind a mark the student
    // has already been told.
    expect(fn () => app(SubmitAssignment::class)->handle($assignment, $student, 'النسخة الثالثة.'))
        ->toThrow(DomainException::class);

    expect(Submission::query()->where('assignment_id', $assignment->getKey())->count())->toBe(1);
});

it('keeps the homework list inside a declared query budget', function (): void {
    Storage::fake('local');

    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);
    $this->createEnrollment($workspace, $course, $student);

    foreach (range(1, 5) as $index) {
        $assignment = Assignment::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'created_by' => $owner->getKey(),
            'due_at' => now()->addDays($index),
            'submission_type' => Assignment::TYPE_FILE,
        ]);

        app(SubmitAssignment::class)->handle(
            $assignment,
            $student,
            null,
            UploadedFile::fake()->create("h{$index}.pdf", 5, 'application/pdf'),
        );
    }

    Sanctum::actingAs($student);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->getJson('/api/v1/assignments')->assertOk();

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    /*
    | ⚠️ THE MEDIA RELATION IS THE POINT. `has_file` asks every row whether it
    | carries a file, and `getFirstMedia()` lazy-loads once per card — a class
    | list of 200 is 200 extra queries, which is the ClassSessionResource defect
    | wearing new clothes. This fails the day somebody drops the eager load.
    */
    expect($queries)->toBeLessThanOrEqual(15);
});

it('refuses a submission type that has no flow behind it', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    Sanctum::actingAs($owner);

    /*
    | ⚠️ FR-044 NAMES THREE WAYS TO HAND WORK IN AND TWO ARE BUILT. Accepting
    | `questions` would give the teacher a plain text box under a label promising
    | bank questions — a silent fallback nobody discovers until a mark is
    | disputed. The refusal is the honest shape of an unfinished feature.
    */
    $this->postJson('/api/v1/manage/assignments', [
        'title' => 'واجب',
        'submission_type' => 'questions',
        'due_at' => now()->addDay()->toIso8601String(),
    ])->assertStatus(422);

    $this->postJson('/api/v1/manage/assignments', [
        'title' => 'واجب',
        'submission_type' => 'text',
        'due_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated();
});

it('hides a draft from the class and shows it to its author', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);

    [$assignment] = courseAssignment($workspace, $owner, $student, [
        'status' => Assignment::STATUS_DRAFT,
        'published_at' => null,
    ]);

    Sanctum::actingAs($student);
    $this->getJson("/api/v1/assignments/{$assignment->uuid}")->assertForbidden();

    Sanctum::actingAs($owner);
    $this->getJson("/api/v1/assignments/{$assignment->uuid}")->assertOk();
});
