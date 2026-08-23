<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Answer;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| SC-004 · FR-006 · FR-009 — work an assistant did stays theirs after they leave.
|
| ⚠️ THE ATTRIBUTION IS A COLUMN, NOT A LOG LINE, and that is why the withdrawal
| cannot touch it. `exam_answers.graded_by` and `grading_records.graded_by` name
| the marker on the row itself; an audit line would be a second copy of the same
| fact, and the two drift the first time one of them is filtered.
|
| ⚠️ AND THE ASSIGNMENT ROW IS REVOKED, NEVER DELETED. Deleting it is the obvious
| tidy-up and it is what breaks this requirement in the least visible way: the
| foreign key still resolves to a user, so nothing errors — the teacher simply
| opens a marked paper months later and finds a name that no longer means
| anything, or worse, is re-used.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    // ⚠️ `name` IS DERIVED, so it is written as its two columns. Assigning it
    // directly is silently dropped on any model and throws on this one.
    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER, null);
    $this->assistant->forceFill(['first_name' => 'سارة', 'last_name' => 'المصحّحة'])->save();

    // Grading is on `$teacher` in the matrix, never seeded onto an assistant —
    // FR-031's whole delivery channel is the owner ticking it onto a custom role
    // for a named person. That grant is exactly what this fixture performs.
    $this->assistant->givePermissionTo(Permissions::GRADING_PERFORM);

    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    [$this->exam, $this->attempt] = sitEssayExam($this->workspace, $student);

    $this->answer = Answer::query()
        ->where('attempt_id', $this->attempt->getKey())
        ->where('requires_grading', true)
        ->whereNull('graded_at')
        ->firstOrFail();
});

it('keeps a mark attributed to the assistant who gave it after they are withdrawn', function (): void {
    Sanctum::actingAs($this->assistant);

    $this->postJson("/api/v1/manage/grading/answers/{$this->answer->uuid}", [
        'marks' => [['criterion_id' => null, 'points' => 7, 'comment' => 'إجابة جيّدة.']],
    ])->assertOk();

    Sanctum::actingAs($this->owner);

    $this->deleteJson("/api/v1/manage/assistants/{$this->assignment->uuid}")->assertNoContent();

    $marked = $this->answer->refresh();

    expect((int) $marked->graded_by)->toBe((int) $this->assistant->getKey())
        ->and($marked->grader?->name)->toBe('سارة المصحّحة')
        ->and($marked->gradingRecords()->pluck('graded_by')->all())
        ->toBe([(int) $this->assistant->getKey()]);
});

it('shows the teacher the marker by name on the paper they open afterwards', function (): void {
    Sanctum::actingAs($this->assistant);

    $this->postJson("/api/v1/manage/grading/answers/{$this->answer->uuid}", [
        'marks' => [['criterion_id' => null, 'points' => 7, 'comment' => 'إجابة جيّدة.']],
    ])->assertOk();

    Sanctum::actingAs($this->owner);
    $this->deleteJson("/api/v1/manage/assistants/{$this->assignment->uuid}")->assertNoContent();

    /*
    | ⚠️ READ THROUGH THE RELATION THE TEACHER'S SCREEN READS, not through the id.
    | A foreign key that resolves is the requirement; a column holding a number
    | nobody can turn into a person satisfies the assertion above and fails FR-006.
    */
    $grader = $this->answer->refresh()->grader;

    expect($grader)->not->toBeNull()
        ->and($grader?->getKey())->toBe($this->assistant->getKey());
});
