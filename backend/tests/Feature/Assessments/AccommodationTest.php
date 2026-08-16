<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GrantAccommodation;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Assessments\Support\ApplyAccommodation;
use App\Modules\Tenancy\Support\Permissions;
use DomainException;
use Laravel\Sanctum\Sanctum;

/*
| SC-018 · FR-053 · FR-054 · FR-056. Granted once, applied everywhere, and known
| to nobody else.
*/

it('applies to an exam and to a homework deadline without being asked twice', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);

    // A course enrolment first: the guard refuses to arrange anything for
    // somebody this workspace does not teach.
    [$assignment] = courseAssignment($workspace, $owner, $student, ['due_at' => now()->subDay()]);

    app(GrantAccommodation::class)->handle(
        (int) $workspace->getKey(),
        $owner,
        $student,
        extraTimePct: 50,
        extendedDays: 2,
        reason: 'قرارٌ من لجنة الدعم.',
    );

    /*
    | ⚠️ BOTH HALVES, IN ONE TEST, BECAUSE ONE WITHOUT THE OTHER IS THE FAILURE.
    | FR-054 asks that the arrangement apply to EVERY assessment by itself; an
    | implementation that moved the deadline but not the exam duration would pass
    | a test of either half alone and fail the student on the other.
    */
    $exam = Exam::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'status' => 'published',
        'duration_minutes' => 60,
    ]);

    $attempt = app(StartAttempt::class)->handle($exam, $student);

    expect($attempt->duration_minutes)->toBe(90);

    // The homework was due yesterday; two extra days means it is not late.
    $submission = app(SubmitAssignment::class)->handle($assignment, $student, 'داخل المهلة.');

    expect($submission->state)->toBe(Submission::STATE_ON_TIME)
        ->and($submission->late_by_minutes)->toBe(0);
});

it('leaves a paper already sat at the duration it was granted', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$assignment] = courseAssignment($workspace, $owner, $student, ['due_at' => now()->addDay()]);

    $accommodation = app(GrantAccommodation::class)->handle(
        (int) $workspace->getKey(),
        $owner,
        $student,
        extraTimePct: 100,
        extendedDays: 0,
        reason: 'ترتيبٌ مؤقّت.',
    );

    $exam = Exam::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'status' => 'published',
        'duration_minutes' => 30,
    ]);

    $attempt = app(StartAttempt::class)->handle($exam, $student);
    expect($attempt->duration_minutes)->toBe(60);

    /*
    | ⚠️ FROZEN, NOT DERIVED. Withdrawing the arrangement must not re-time a
    | paper somebody already sat — the same class of drift `attempt_items.points`
    | exists to prevent. Derived on read, this number would change under a
    | finished exam.
    */
    app(GrantAccommodation::class)->revoke($accommodation, $owner);

    expect($attempt->refresh()->duration_minutes)->toBe(60);

    // And it applies to nothing afterwards.
    expect(app(ApplyAccommodation::class)->extendedDays((int) $workspace->getKey(), (int) $student->getKey()))->toBe(0);

    // The assignment is unaffected either way: it was never late.
    expect($assignment->fresh()->due_at)->not->toBeNull();
});

it('refuses to arrange anything for somebody this workspace does not teach', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    // A member with no enrolment: NFR-001أ draws the line at an enrolment, and a
    // bare uuid parameter is otherwise an identity probe whose answer comes back
    // carrying somebody's name.
    $stranger = $this->addWorkspaceMember($workspace);

    expect(fn () => app(GrantAccommodation::class)->handle(
        (int) $workspace->getKey(),
        $owner,
        $stranger,
        extraTimePct: 50,
        extendedDays: 1,
        reason: 'بلا سبب حقيقي.',
    ))->toThrow(DomainException::class);
});

it('answers 404 for a stranger and for a uuid that names nobody, identically', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $stranger = $this->addWorkspaceMember($workspace);

    Sanctum::actingAs($owner);

    $payload = ['extra_time_pct' => 25, 'extended_days' => 1, 'reason' => 'محاولة.'];

    /*
    | ⚠️ ONE ANSWER FOR BOTH, and that is the requirement rather than a
    | convenience. A reply that told "no such person" apart from "not your
    | student" confirms the uuid names a real account — the same probe in a more
    | precise form.
    */
    $real = $this->postJson('/api/v1/manage/accommodations', [
        ...$payload,
        'student_uuid' => $stranger->uuid,
    ])->assertNotFound();

    $this->postJson('/api/v1/manage/accommodations', [
        ...$payload,
        'student_uuid' => '00000000-0000-4000-8000-000000000000',
    ])->assertNotFound();

    // And the refusal carries nothing about them. A 404 whose body named the
    // person would be the probe answering itself.
    expect($real->getContent())->not->toContain($stranger->name)
        ->and($real->getContent())->not->toContain($stranger->email);
});

it('does not tell a classmate that an arrangement exists', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $classmate = $this->addWorkspaceMember($workspace);

    [$assignment, $course] = courseAssignment($workspace, $owner, $student, ['due_at' => now()->subDay()]);
    $this->createEnrollment($workspace, $course, $classmate);

    app(GrantAccommodation::class)->handle(
        (int) $workspace->getKey(),
        $owner,
        $student,
        extraTimePct: 0,
        extendedDays: 3,
        reason: 'ترتيبٌ خاص.',
    );

    app(SubmitAssignment::class)->handle($assignment, $student, 'داخل المهلة.');

    Sanctum::actingAs($classmate);

    // No list endpoint reaches them at all.
    $this->getJson('/api/v1/manage/accommodations')->assertForbidden();

    /*
    | ⚠️ AND THE ASSIGNMENT PAYLOAD CARRIES THE ASSIGNMENT'S OWN DATE, never
    | anybody's effective one. A shared object that quietly differed per reader
    | would announce the arrangement by arithmetic (FR-056).
    */
    $payload = $this->getJson("/api/v1/assignments/{$assignment->uuid}")->assertOk()->json('data');

    expect($payload['my_submission'])->toBeNull()
        ->and($payload['due_at'])->not->toBeNull();
});

it('refuses the grant to somebody without the permission', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $assistant = $this->addWorkspaceMember($workspace);

    courseAssignment($workspace, $owner, $student, ['due_at' => now()->addDay()]);

    Sanctum::actingAs($assistant);

    $this->postJson('/api/v1/manage/accommodations', [
        'student_uuid' => $student->uuid,
        'extra_time_pct' => 50,
        'extended_days' => 1,
        'reason' => 'محاولة.',
    ])->assertForbidden();

    $assistant->givePermissionTo(Permissions::ACCOMMODATIONS_MANAGE);

    $this->postJson('/api/v1/manage/accommodations', [
        'student_uuid' => $student->uuid,
        'extra_time_pct' => 50,
        'extended_days' => 1,
        'reason' => 'قرارٌ من اللجنة.',
    ])->assertCreated();
});
