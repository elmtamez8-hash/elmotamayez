<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GrantUnlockExemption;
use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\UnlockRule;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Contracts\UnlockDirectory;
use Laravel\Sanctum\Sanctum;

/*
| SC-012 · FR-036 → FR-042. The five rows of quickstart §9, plus the branches a
| happy test does not reach.
*/

function unlockDefault(int $workspaceId, bool $attendance = true, bool $assignment = true, float $minScore = 0): UnlockRule
{
    return UnlockRule::factory()->create([
        'workspace_id' => $workspaceId,
        'requires_attendance' => $attendance,
        'requires_assignment' => $assignment,
        'min_score_pct' => $minScore,
    ]);
}

it('opens the next session for a student who attended and handed in', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second, $course] = gatedPair($workspace, $student);

    unlockDefault((int) $workspace->getKey());
    attendanceRow($workspace, $first, $student, AttendanceStatus::Present);

    $homework = Assignment::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'class_session_id' => $first->getKey(),
        'created_by' => $owner->getKey(),
        'due_at' => now()->addDay(),
        'points' => 10,
    ]);

    app(SubmitAssignment::class)->handle($homework, $student, 'حلّي.');

    expect(app(UnlockDirectory::class)->refusalFor($student, (int) $second->getKey()))->toBeNull();
});

it('names the homework when that is what is missing, and the attendance when that is', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second, $course] = gatedPair($workspace, $student);

    unlockDefault((int) $workspace->getKey());

    Assignment::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'class_session_id' => $first->getKey(),
        'created_by' => $owner->getKey(),
        'due_at' => now()->addDay(),
    ]);

    // Attended, nothing handed in.
    attendanceRow($workspace, $first, $student, AttendanceStatus::Present);

    $directory = app(UnlockDirectory::class);
    $refusal = $directory->refusalFor($student, (int) $second->getKey());

    /*
    | ⚠️ THE SENTENCE IS THE REQUIREMENT (FR-038). «غير متاح» turns a motivation
    | feature into an outage the student emails their teacher about — the one
    | thing they cannot work out for themselves is what to go and do.
    */
    expect($refusal)->toContain('تسليم واجبها')
        ->and($refusal)->not->toContain('حضور');
});

it('shuts the session for a student who did not attend', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second] = gatedPair($workspace, $student);

    unlockDefault((int) $workspace->getKey(), attendance: true, assignment: false);
    attendanceRow($workspace, $first, $student, AttendanceStatus::Absent);

    expect(app(UnlockDirectory::class)->refusalFor($student, (int) $second->getKey()))
        ->toContain('حضور الحصة السابقة');
});

it('counts an excused absence as attendance', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second] = gatedPair($workspace, $student);

    unlockDefault((int) $workspace->getKey(), attendance: true, assignment: false);

    /*
    | ⚠️ ONLY `absent` FAILS, AND THIS IS THE READING SOMEBODY WILL "CORRECT".
    | An excusal is the teacher's own decision that the absence is not held
    | against the student; a gate that then holds it against them contradicts the
    | person who granted it, and the exemption below would exist only to undo the
    | teacher's other hand.
    */
    attendanceRow($workspace, $first, $student, AttendanceStatus::Excused);

    expect(app(UnlockDirectory::class)->refusalFor($student, (int) $second->getKey()))->toBeNull();
});

it('lets an exempted student past, with the reason recorded', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second] = gatedPair($workspace, $student);

    unlockDefault((int) $workspace->getKey(), attendance: true, assignment: false);
    attendanceRow($workspace, $first, $student, AttendanceStatus::Absent);

    expect(app(UnlockDirectory::class)->refusalFor($student, (int) $second->getKey()))->not->toBeNull();

    $exemption = app(GrantUnlockExemption::class)->handle(
        (int) $workspace->getKey(),
        (int) $second->getKey(),
        $owner,
        $student,
        'ظرفٌ عائلي.',
    );

    expect(app(UnlockDirectory::class)->refusalFor($student, (int) $second->getKey()))->toBeNull()
        ->and($exemption->reason)->toBe('ظرفٌ عائلي.')
        ->and((int) $exemption->granted_by)->toBe((int) $owner->getKey());
});

it('does not let an unpublished assignment shut anything', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second, $course] = gatedPair($workspace, $student);

    unlockDefault((int) $workspace->getKey(), attendance: false, assignment: true);

    /*
    | ⚠️ FR-042. A teacher who started writing homework on Tuesday and never
    | finished must not thereby have locked their whole class out of Wednesday —
    | with no message naming a cause anybody can act on.
    */
    Assignment::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'class_session_id' => $first->getKey(),
        'created_by' => $owner->getKey(),
        'due_at' => now()->subDay(),
    ]);

    expect(app(UnlockDirectory::class)->refusalFor($student, (int) $second->getKey()))->toBeNull();
});

it('opens a session with no countable predecessor', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second] = gatedPair($workspace, $student);

    unlockDefault((int) $workspace->getKey());

    /*
    | ⚠️ A CANCELLED CLASS IS NOT A PREDECESSOR. Nobody could attend it, so
    | gating on it would shut the rest of the course behind a session that never
    | happened — and a freeze, which exists to protect the student, would become
    | the thing that locks them out.
    */
    $first->forceFill(['status' => 'cancelled'])->save();

    expect(app(UnlockDirectory::class)->refusalFor($student, (int) $second->getKey()))->toBeNull();

    // And the very first session of a course, which has nothing behind it at all.
    $lonely = ClassSession::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => null,
        'starts_at' => now()->addMonth(),
        'ends_at' => now()->addMonth()->addHour(),
    ]);

    expect(app(UnlockDirectory::class)->refusalFor($student, (int) $lonely->getKey()))->toBeNull();
});

it('opens everything in a workspace that has configured no rule', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second] = gatedPair($workspace, $student);

    // No UnlockRule row at all — the state every existing workspace is in on the
    // day this ships. FR-037 makes the default optional, so its absence is "no
    // condition", never "blocked until somebody configures it".
    attendanceRow($workspace, $first, $student, AttendanceStatus::Absent);

    expect(app(UnlockDirectory::class)->refusalFor($student, (int) $second->getKey()))->toBeNull();
});

it('does not shut a paper the teacher has not marked yet', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second, $course] = gatedPair($workspace, $student);

    unlockDefault((int) $workspace->getKey(), attendance: false, assignment: true, minScore: 80);

    $homework = Assignment::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'class_session_id' => $first->getKey(),
        'created_by' => $owner->getKey(),
        'due_at' => now()->addDay(),
        'points' => 10,
    ]);

    app(SubmitAssignment::class)->handle($homework, $student, 'حلّي.');

    /*
    | ⚠️ SUBMITTED BUT UNMARKED SATISFIES THE THRESHOLD. The student has done
    | everything asked of them; blocking here makes the teacher's marking queue
    | into a gate on the whole class — the person who is late is the teacher, and
    | the person shut out is not.
    */
    expect(app(UnlockDirectory::class)->refusalFor($student, (int) $second->getKey()))->toBeNull();
});

it('answers the eligibility endpoint with what is missing and which rule decided', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second] = gatedPair($workspace, $student);

    unlockDefault((int) $workspace->getKey(), attendance: true, assignment: false);
    attendanceRow($workspace, $first, $student, AttendanceStatus::Absent);

    Sanctum::actingAs($student);

    $payload = $this->getJson("/api/v1/class-sessions/{$second->uuid}/eligibility")
        ->assertOk()
        ->json('data');

    expect($payload['open'])->toBeFalse()
        ->and($payload['unlock']['missing'])->toBe(['attendance'])
        // Precedence is invisible without this: a teacher with a default AND a
        // course override cannot tell which of the two refused.
        ->and($payload['unlock']['rule_scope'])->toBe('default')
        ->and($payload['unlock']['reason'])->toContain('حضور');
});

it('refuses the booking itself, not merely the screen', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second, $course] = gatedPair($workspace, $student);

    unlockDefault((int) $workspace->getKey(), attendance: true, assignment: false);
    attendanceRow($workspace, $first, $student, AttendanceStatus::Absent);

    // ⚠️ FUNDED FIRST, AND THE ORDER IS ITSELF THE ASSERTION. 006's withholding
    // refuses before this gate is reached, so a student with no credits would
    // fail this test for the wrong reason — and the money condition coming first
    // is correct: telling somebody to do their homework when the real obstacle
    // is an unpaid balance sends them to do the wrong thing.
    fundBooking($workspace, $student, $course, 10);

    $second->forceFill(['seats_total' => 5, 'seats_taken' => 0])->save();

    Sanctum::actingAs($student);

    /*
    | ⚠️ THE SERVER, NOT THE SIDEBAR. FR-038's «يُمنع» and FR-041's «عند كل طلب»
    | are server language; a gate that only hid a button would be the exact
    | defect this product was already caught with once — a menu of links that
    | answer 403, or worse, do not.
    */
    // 409 rather than 422, which is 005's own convention on this route: nothing
    // about the request was malformed — the state of the world is what refuses.
    // The message names the missing piece, so the screen can explain.
    $response = $this->postJson("/api/v1/class-sessions/{$second->uuid}/book")->assertStatus(409);

    expect($response->json('message'))->toContain('حضور الحصة السابقة');

    // The register is corrected — one row per student per session, so this is an
    // update rather than a second row.
    Attendance::query()
        ->where('class_session_id', $first->getKey())
        ->where('student_user_id', $student->getKey())
        ->update(['status' => AttendanceStatus::Present->value]);

    // FR-039 — satisfying the condition opens it on the very next request, with
    // no sweep in between.
    $this->postJson("/api/v1/class-sessions/{$second->uuid}/book")->assertCreated();
});
