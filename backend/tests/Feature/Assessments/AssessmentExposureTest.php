<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Support\AssessmentFieldAllowlist;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| NFR-007 · FR-020 · FR-049 · FR-056 · FR-048. What a student may be shown.
|
| ⚠️ TWO HALVES, AND ONLY THE SECOND CATCHES THE BUGS WORTH CATCHING. The first
| walks the enumerated payloads against `AssessmentFieldAllowlist` — cheap, and
| it fails the day a Resource grows a column. The second is adversarial: student
| B asks for student A's rows on every route that takes a uuid. A field list
| passes `score` and `answer_text` without knowing whose they are, so a payload
| that returns the WRONG ROW with entirely correct FIELDS is green in the first
| half and caught only in the second.
*/

/**
 * A response body with its Arabic readable.
 *
 * ⚠️ `getContent()` ESCAPES NON-ASCII, so `not->toContain('حلُّ زميلي.')` against
 * the raw string is vacuously true whatever the payload holds — the body carries
 * `حل...` and the needle never matches. Every leak assertion in this
 * file is on Arabic text, so every one of them would have passed on a response
 * that leaked everything.
 */
function bodyText(mixed $response): string
{
    return (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE);
}

/** Every string key in a payload, however deeply nested. */
function keysDeep(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = array_merge($keys, keysDeep($child));
    }

    return array_values(array_unique($keys));
}

/*
|--------------------------------------------------------------------------
| Half one — the fields
|--------------------------------------------------------------------------
*/

it('hands a sitting student the options and not the mark scheme', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    $exam = Exam::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'status' => 'published',
        'max_attempts' => 3,
    ]);

    $question = practiceQuestion($workspace, 'ما عاصمة قطر؟');
    ExamItem::create([
        'workspace_id' => $workspace->getKey(),
        'exam_id' => $exam->getKey(),
        'question_id' => $question->getKey(),
        'order' => 1,
    ]);

    Sanctum::actingAs($student);

    $payload = $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")
        ->assertCreated()
        ->json();

    /*
    | ⚠️ THE EXACT OPTION SHAPE, NOT «CONTAINS ID AND CONTENT». `QuestionOption`
    | carries `is_correct`, so the failure this guards is serialising the model
    | instead of mapping it — and that failure ADDS a key rather than removing
    | one. An assertion that only checks the two expected keys are present
    | passes against a payload carrying the answer beside them.
    */
    expect(array_keys($payload['questions'][0]['options'][0]))
        ->toBe(AssessmentFieldAllowlist::sitOptionFields());

    $keys = keysDeep($payload);

    foreach (AssessmentFieldAllowlist::forbiddenDuringAttempt() as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }

    foreach (AssessmentFieldAllowlist::forbidden() as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
});

it('keeps internal keys out of every student-facing payload', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    [$assignment] = courseAssignment($workspace, $owner, $student);

    app(SubmitAssignment::class)->handle($assignment, $student, 'حلّي.');

    $question = bankQuestion($workspace, null, ['content' => 'أخطأتُ هنا؟']);
    answerRow((int) $workspace->getKey(), $student, (int) $question->getKey(), false);

    Sanctum::actingAs($student);

    // The enumerated list. A payload absent from it is a payload nothing walks —
    // which is why the list is here in the test rather than derived from routes:
    // deriving it would silently cover a new endpoint and silently miss one that
    // takes a parameter the derivation could not guess.
    $payloads = [
        '/api/v1/mistakes',
        '/api/v1/assignments',
        "/api/v1/assignments/{$assignment->uuid}",
    ];

    foreach ($payloads as $url) {
        $keys = keysDeep($this->getJson($url)->assertOk()->json());

        foreach (AssessmentFieldAllowlist::forbidden() as $forbidden) {
            expect($keys)->not->toContain($forbidden, "{$url} leaked {$forbidden}");
        }
    }
});

/*
|--------------------------------------------------------------------------
| Half two — the rows
|--------------------------------------------------------------------------
*/

it('refuses one student the attempt of another', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $mine = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    $theirs = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    [, $attempt] = sitEssayExam($workspace, $theirs);

    Sanctum::actingAs($mine);

    /*
    | ⚠️ THE TWO ARE CLASSMATES IN ONE WORKSPACE, which is the case no tenant
    | scope can answer. `WorkspaceScope` is satisfied — both rows are in the same
    | workspace — so the only thing standing between them is the policy asking
    | who the attempt belongs to.
    */
    $this->getJson("/api/v1/attempts/{$attempt->uuid}")->assertForbidden();
});

it('refuses one student the practice result of another', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $mine = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    $theirs = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    [, $attempt] = sitEssayExam($workspace, $theirs);

    Sanctum::actingAs($mine);

    $this->getJson("/api/v1/practice/attempts/{$attempt->uuid}/result")
        ->assertForbidden();
});

it('tells one student nothing about a classmate through the submission file link', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $mine = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    $theirs = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    [$assignment] = courseAssignment($workspace, $owner, $theirs);
    $submission = app(SubmitAssignment::class)->handle($assignment, $theirs, 'حلُّ زميلي.');

    Sanctum::actingAs($mine);

    $response = $this->getJson("/api/v1/submissions/{$submission->uuid}/file");

    // Unsigned, so it never reaches the policy — but the assertion that matters
    // is on the BODY: a refusal that names the owner has already answered the
    // question the request was asking.
    $body = $response->getContent().bodyText($response);

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and($body)->not->toContain($theirs->email)
        ->and($body)->not->toContain('حلُّ زميلي.');
});

it('shows a student their own submission and no sign of anyone else', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $mine = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    $theirs = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    [$assignment, $course] = courseAssignment($workspace, $owner, $mine);
    $this->createEnrollment($workspace, $course, $theirs);

    app(SubmitAssignment::class)->handle($assignment, $mine, 'حلّي أنا.');
    app(SubmitAssignment::class)->handle($assignment, $theirs, 'حلُّ زميلي.');

    Sanctum::actingAs($mine);

    $body = bodyText($this->getJson("/api/v1/assignments/{$assignment->uuid}")->assertOk());

    /*
    | ⚠️ FR-056 IN ITS OTHER DIRECTION. `my_submission` is a singular field, so
    | the failure mode is not a list of everyone — it is the branch that picks
    | the wrong row when two exist. One submission in the fixture would make this
    | assertion pass against a query with no student filter at all.
    */
    expect($body)->toContain('حلّي أنا.')
        ->and($body)->not->toContain('حلُّ زميلي.')
        ->and($body)->not->toContain($theirs->email);
});
