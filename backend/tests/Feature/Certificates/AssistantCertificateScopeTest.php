<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Certificates\Actions\IssueCertificate;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| Spec 010 · FR-005 on certificates (audit 2026-09-30).
|
| ⚠️ `certificates.view.all` IS ON THE ASSISTANT ROLE BY DEFAULT, and the list
| answered it with the whole workspace: an assistant confined to one course read
| every other course's graduates by name, and opened (and, holding
| `certificates.regenerate`, re-issued) any of their certificates by uuid.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->near = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey(), 'is_sequential' => false]);
    $this->far = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey(), 'is_sequential' => false]);

    $this->student = User::factory()->create();

    $this->nearCert = scopedCertificateFor($this->near, $this->student);
    $this->farCert = scopedCertificateFor($this->far, $this->student);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    // Not on the default role; a teacher may tick it for an assistant.
    $this->assistant->givePermissionTo(Permissions::CERTIFICATES_REGENERATE);

    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

function scopedCertificateFor(Course $course, User $student): Certificate
{
    $enrollment = Enrollment::create([
        'workspace_id' => $course->workspace_id, 'uuid' => Str::uuid(), 'course_id' => $course->getKey(),
        'student_user_id' => $student->getKey(), 'status' => 'completed', 'enrolled_at' => now(),
        'completed_at' => now(),
    ]);

    return app(IssueCertificate::class)->handle($enrollment, 'course_completed');
}

function confineCertificateReaderTo(Course $course): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => $course->getKey(),
    ]);

    app()->forgetScopedInstances();
}

/** @return list<string> */
function listedCertificateUuids(string $query = ''): array
{
    return collect(test()->getJson('/api/v1/certificates'.$query)->assertOk()->json('data'))
        ->pluck('uuid')
        ->all();
}

it('lists a confined assistant the certificates of their own courses only', function (): void {
    confineCertificateReaderTo($this->near);
    Sanctum::actingAs($this->assistant);

    expect(listedCertificateUuids())->toBe([$this->nearCert->uuid]);
    expect(listedCertificateUuids("?course={$this->far->uuid}"))->toBe([]);
    expect(listedCertificateUuids("?course={$this->near->uuid}"))->toBe([$this->nearCert->uuid]);

    $this->getJson('/api/v1/certificates')->assertJsonPath('meta.total', 1);
});

it('refuses a confined assistant a far certificate and its regeneration', function (): void {
    confineCertificateReaderTo($this->near);
    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/certificates/{$this->farCert->uuid}")->assertForbidden();
    $this->postJson("/api/v1/certificates/{$this->farCert->uuid}/regenerate")->assertForbidden();

    $this->getJson("/api/v1/certificates/{$this->nearCert->uuid}")->assertOk();
    $this->postJson("/api/v1/certificates/{$this->nearCert->uuid}/regenerate")->assertOk();
});

it('leaves an unconfined assistant and the owner on the whole workspace', function (): void {
    foreach ([$this->assistant, $this->owner] as $reader) {
        Sanctum::actingAs($reader);

        expect(listedCertificateUuids())->toEqualCanonicalizing([$this->nearCert->uuid, $this->farCert->uuid]);
        $this->getJson("/api/v1/certificates/{$this->farCert->uuid}")->assertOk();
    }
});

it('leaves the student\'s own certificates alone', function (): void {
    confineCertificateReaderTo($this->near);

    $this->asGuest();
    Sanctum::actingAs($this->student);

    expect(listedCertificateUuids())->toEqualCanonicalizing([$this->nearCert->uuid, $this->farCert->uuid]);
    $this->getJson("/api/v1/certificates/{$this->farCert->uuid}")->assertOk();
});

it('shows a confined assistant nothing of another workspace', function (): void {
    confineCertificateReaderTo($this->near);

    [$other] = $this->createWorkspaceWithOwner();
    $theirs = app(WorkspaceContext::class)->forWorkspace($other, fn (): Certificate => scopedCertificateFor(
        Course::factory()->published()->create(['workspace_id' => $other->getKey(), 'is_sequential' => false]),
        User::factory()->create(),
    ));

    Sanctum::actingAs($this->assistant);

    expect(listedCertificateUuids())->not->toContain($theirs->uuid);
    $this->getJson("/api/v1/certificates/{$theirs->uuid}")->assertForbidden();
});
