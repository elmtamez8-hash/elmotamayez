<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

/**
 * FR-071 as a security constraint, not a UI note.
 *
 * Step 3 collects an acknowledgement. Identity documents need a retention policy,
 * an access-control model and a deletion path, none of which exist yet — so the
 * endpoint refuses a file rather than accepting one it cannot look after. It must
 * fail loudly: quietly discarding an upload would let the client report success
 * for a document that was never stored.
 */
beforeEach(function (): void {
    // ⚠️ Spec 025 deleted `PlatformWorkspace`. The application row needs A
    // workspace — every tenant row does — and which one is beside the point for
    // this test, so it gets an ordinary one instead of a shared container that
    // no longer exists.
    [$workspace] = $this->createWorkspaceWithOwner();

    $this->applicant = User::factory()->create();

    app(WorkspaceContext::class)->forWorkspace($workspace, fn () => TeacherApplication::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $this->applicant->getKey(),
        'current_step' => 3,
    ]));

    Sanctum::actingAs($this->applicant);
    $this->asGuest();
    Sanctum::actingAs($this->applicant);
});

it('accepts the acknowledgement on its own', function (): void {
    $this->putJson('/api/v1/teacher/application/step-3', ['documents_acknowledged' => true])
        ->assertOk()
        ->assertJsonPath('application.step_data.step_3.documents_acknowledged', true);
});

it('rejects an uploaded file with 422', function (): void {
    $this->put('/api/v1/teacher/application/step-3', [
        'documents_acknowledged' => true,
        'certificate' => UploadedFile::fake()->create('id-card.pdf', 120, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['documents']);
});

it('rejects a document smuggled in as a field', function (array $payload): void {
    $this->putJson('/api/v1/teacher/application/step-3', [
        'documents_acknowledged' => true,
        ...$payload,
    ])->assertStatus(422)->assertJsonValidationErrors(['documents']);
})->with([
    'base64 blob' => [['certificate' => 'data:application/pdf;base64,JVBERi0xLjQK']],
    'remote url' => [['id_document_url' => 'https://example.com/passport.jpg']],
    'nested array' => [['documents' => [['name' => 'id', 'content' => 'x']]]],
]);

it('stores nothing when the payload is refused', function (): void {
    $this->putJson('/api/v1/teacher/application/step-3', [
        'documents_acknowledged' => true,
        'certificate' => 'data:application/pdf;base64,JVBERi0xLjQK',
    ])->assertStatus(422);

    $application = TeacherApplication::withoutWorkspaceScope()
        ->where('user_id', $this->applicant->getKey())
        ->sole();

    expect($application->step(3))->toBe([]);
});
